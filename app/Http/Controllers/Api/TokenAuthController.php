<?php

namespace App\Http\Controllers\Api;

use App\Data\Auth\MobileDeviceData;
use App\Enums\AuthCredentialType;
use App\Enums\TokenRevokeReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\MobileLoginRequest;
use App\Http\Requests\Api\Auth\MobileRegisterRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\Api\MobileTokenPairResource;
use App\Http\Resources\Api\UserResource;
use App\Models\PersonalAccessToken as AppPersonalAccessToken;
use App\Services\Auth\AuthChallengeService;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use App\Services\Auth\TokenIssuer;
use App\Services\Auth\UserRegistrationService;
use App\Services\Security\SecurityTelemetry;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TokenAuthController extends Controller
{
    private const array SENSITIVE_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    public function registerMobile(
        MobileRegisterRequest $request,
        UserRegistrationService $registration,
        MobileTokenService $tokens,
        DeviceFingerprint $fingerprint,
    ): JsonResponse {
        $user = $registration->register($request->validated());
        $pair = $tokens->issue(
            $user,
            MobileDeviceData::fromRequest($request, $fingerprint),
        );

        return MobileTokenPairResource::make($pair)
            ->additional(['message' => __('auth.messages.account_created')])
            ->response()
            ->setStatusCode(201);
    }

    public function loginMobile(
        MobileLoginRequest $request,
        CredentialAuthenticator $credentials,
        AuthChallengeService $challenges,
        MobileTokenService $tokens,
        DeviceFingerprint $fingerprint,
        SecurityTelemetry $telemetry,
        TokenAbilityRegistry $abilities,
    ): JsonResponse {
        $data = $request->validated();
        $user = $credentials->authenticate($data['email'], $data['password'], $request, 'mobile');
        $device = MobileDeviceData::fromRequest($request, $fingerprint);

        if ($user->hasEnabledTwoFactor()) {
            $issuedChallenge = $challenges->issue(
                user: $user,
                credentialType: AuthCredentialType::TOKEN,
                request: $request,
                deviceName: $data['device_name'],
                abilities: $abilities->abilitiesFor(TokenAbilityProfile::MOBILE),
                mobileDevice: $device,
            );

            return $this->challengeResponse($user, $issuedChallenge);
        }

        $pair = $tokens->issue($user, $device);
        $telemetry->loginSucceeded($user, $request, 'mobile', AuthCredentialType::TOKEN);

        return MobileTokenPairResource::make($pair)
            ->additional(['message' => __('auth.messages.logged_in')])
            ->response();
    }

    public function register(
        RegisterRequest $request,
        UserRegistrationService $registration,
        TokenIssuer $tokens,
    ): JsonResponse {
        $data = $request->validated();
        $user = $registration->register($data);

        return response()->json([
            'message' => __('auth.messages.account_created'),
            'data' => $this->serializeTokenData($tokens->issue($user, $data['device_name'], $request)),
        ], 201, self::SENSITIVE_RESPONSE_HEADERS);
    }

    public function login(
        LoginRequest $request,
        CredentialAuthenticator $credentials,
        AuthChallengeService $challenges,
        TokenIssuer $tokens,
        SecurityTelemetry $telemetry,
        TokenAbilityRegistry $abilities,
    ): JsonResponse {
        $data = $request->validated();
        $user = $credentials->authenticate($data['email'], $data['password'], $request, 'legacy_token');

        if ($user->hasEnabledTwoFactor()) {
            $issuedChallenge = $challenges->issue(
                $user,
                AuthCredentialType::TOKEN,
                $request,
                $data['device_name'],
                $abilities->abilitiesFor(TokenAbilityProfile::LEGACY),
            );

            return $this->challengeResponse($user, $issuedChallenge);
        }

        $tokenData = $tokens->issue($user, $data['device_name'], $request);
        $telemetry->loginSucceeded($user, $request, 'legacy_token', AuthCredentialType::TOKEN);

        return response()->json([
            'message' => __('auth.messages.logged_in'),
            'data' => $this->serializeTokenData($tokenData),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    public function logout(Request $request, MobileTokenService $mobileTokens): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return response()->json([
                'message' => __('auth.errors.bearer_token_required'),
                'code' => 'token_credential_required',
            ], 409);
        }

        if ($accessToken instanceof AppPersonalAccessToken && $accessToken->deviceSession) {
            $mobileTokens->revokeDeviceSession($accessToken->deviceSession, TokenRevokeReason::LOGOUT);
        } else {
            $accessToken->delete();
        }

        return response()->json(['message' => __('auth.messages.logged_out')]);
    }

    public function logoutAll(Request $request, MobileTokenService $mobileTokens): JsonResponse
    {
        return $this->revokeAllTokens($request, $mobileTokens);
    }

    public function logoutAllMobile(Request $request, MobileTokenService $mobileTokens): JsonResponse
    {
        return $this->revokeAllTokens($request, $mobileTokens);
    }

    private function revokeAllTokens(Request $request, MobileTokenService $mobileTokens): JsonResponse
    {
        $mobileTokens->revokeAll($request->user(), TokenRevokeReason::LOGOUT_ALL);
        $request->user()->tokens()->delete();

        return response()->json(['message' => __('auth.messages.api_tokens_revoked')]);
    }

    private function serializeTokenData(array $data): array
    {
        return [
            ...$data,
            'user' => UserResource::make($data['user']),
        ];
    }

    private function challengeResponse($user, $issuedChallenge): JsonResponse
    {
        return response()->json([
            'message' => __('auth.messages.two_factor_required'),
            'code' => 'two_factor_required',
            'data' => [
                'challenge_token' => $issuedChallenge->plainTextToken,
                'method' => $user->two_factor_method->value,
                'recovery_available' => ! empty($user->two_factor_recovery_codes),
                'expires_at' => $issuedChallenge->challenge->expires_at,
            ],
        ], 202, self::SENSITIVE_RESPONSE_HEADERS);
    }
}
