<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenRevokeReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\PersonalAccessToken as AppPersonalAccessToken;
use App\Models\User;
use App\Services\Auth\AccessRevocationService;
use App\Services\Auth\MobileTokenService;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Get the authenticated user's account and profile. */
    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('profile'));
    }

    /** Update the authenticated user's profile. */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->profile()->updateOrCreate(
            ['user_uuid' => $user->uuid],
            $request->validated(),
        );

        return UserResource::make($user->fresh()->load('profile'));
    }

    /** Change the password and revoke other access. */
    public function changePassword(
        ChangePasswordRequest $request,
        AccessRevocationService $revocation,
        SecurityTelemetry $telemetry,
    ): JsonResponse {
        $requestUser = $request->user();
        $user = DB::transaction(function () use ($request, $requestUser, $revocation): User {
            $lockedUser = User::query()
                ->whereKey($requestUser->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! Hash::check($request->validated('current_password'), $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => [__('pages/account.password.current_password_helper')],
                ]);
            }

            $lockedUser->withAccessToken($requestUser->currentAccessToken());
            $lockedUser->forceFill(['password' => $request->validated('password')])->save();
            $revocation->revokeOtherAccess(
                $lockedUser,
                $request,
                TokenRevokeReason::PASSWORD_CHANGED,
            );

            return $lockedUser;
        });

        if ($request->hasSession()
            && Auth::guard('web')->id() === $user->getAuthIdentifier()) {
            Auth::guard('web')->setUser($user);
        }

        $telemetry->passwordChanged(
            $user,
            $request,
            $requestUser->currentAccessToken() instanceof PersonalAccessToken
                ? 'api_token'
                : 'nuxt_session',
        );

        return response()->json(['message' => __('Password changed successfully.')]);
    }

    /** List the authenticated user's API tokens and devices. */
    public function tokens(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();
        $currentTokenId = $accessToken instanceof PersonalAccessToken ? $accessToken->getKey() : null;
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($token): array => [
            'id' => $token->getKey(),
            'name' => $token->name,
            'client_type' => $token->client_type?->value ?? 'legacy',
            'device_session_id' => $token->api_device_session_id,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'created_at' => $token->created_at,
            'current' => $currentTokenId !== null
                && (string) $token->getKey() === (string) $currentTokenId,
        ]);

        return response()->json(['data' => $tokens]);
    }

    /** Revoke one API token owned by the authenticated user. */
    public function revokeToken(
        Request $request,
        int $token,
        MobileTokenService $mobileTokens,
    ): JsonResponse {
        $accessToken = $request->user()->tokens()->whereKey($token)->first();

        abort_if(! $accessToken, 404);

        if ($accessToken instanceof AppPersonalAccessToken && $accessToken->deviceSession) {
            $mobileTokens->revokeDeviceSession(
                $accessToken->deviceSession,
                TokenRevokeReason::USER_REVOKED,
            );
        } else {
            $accessToken->delete();
        }

        return response()->json(['message' => __('API token revoked.')]);
    }
}
