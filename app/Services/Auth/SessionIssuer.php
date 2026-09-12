<?php

namespace App\Services\Auth;

use App\Models\LoginSession;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionIssuer
{
    public function __construct(private readonly AuthSignature $authSignature) {}

    public function login(User $user, Request $request): void
    {
        $expectedAuthSignature = $this->authSignature->for($user);

        DB::transaction(function () use ($user, $request, $expectedAuthSignature): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->is_inactive
                || ! hash_equals($expectedAuthSignature, $this->authSignature->for($lockedUser))) {
                throw new HttpResponseException(response()->json([
                    'message' => __('auth.errors.authentication_state_changed'),
                    'code' => 'authentication_state_changed',
                ], 401));
            }

            Auth::guard('web')->login($lockedUser, false);
            $request->session()->regenerate();
            $request->session()->forget('auth_challenge_binding');

            $sessionId = $request->session()->getId();
            $request->session()->put('login_session_registry_id', $sessionId);

            LoginSession::query()->updateOrCreate(
                ['id' => $sessionId],
                [
                    'user_uuid' => $lockedUser->uuid,
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                    'last_active_at' => now(),
                    'revoked_at' => null,
                ],
            );
        });
    }

    public function logout(Request $request): void
    {
        $registryId = (string) $request->session()->get(
            'login_session_registry_id',
            $request->session()->getId(),
        );

        LoginSession::query()->find($registryId)?->revoke('logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
