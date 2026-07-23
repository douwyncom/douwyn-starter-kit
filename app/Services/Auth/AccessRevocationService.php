<?php

namespace App\Services\Auth;

use App\Enums\TokenRevokeReason;
use App\Models\AccountActionToken;
use App\Models\AuthChallenge;
use App\Models\PersonalAccessToken as AppPersonalAccessToken;
use App\Models\TwoFactorCode;
use App\Models\TwoFactorSetup;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AccessRevocationService
{
    public function __construct(
        private readonly MobileTokenService $mobileTokens,
        private readonly SecurityTelemetry $telemetry,
    ) {}

    public function revokeOtherAccess(
        User $user,
        Request $request,
        TokenRevokeReason $reason = TokenRevokeReason::SECURITY_CHANGED,
        bool $anonymous = false,
    ): void {
        $accessToken = $user->currentAccessToken();
        $currentTokenId = $accessToken instanceof PersonalAccessToken ? $accessToken->getKey() : null;
        $currentDeviceSessionId = $accessToken instanceof AppPersonalAccessToken
            ? $accessToken->api_device_session_id
            : null;

        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();

        $user->apiDeviceSessions()
            ->whereNull('revoked_at')
            ->when($currentDeviceSessionId, fn ($query) => $query->whereKeyNot($currentDeviceSessionId))
            ->get()
            ->each(fn ($deviceSession) => $this->mobileTokens->revokeDeviceSession(
                $deviceSession,
                $reason,
                $anonymous,
            ));

        $browserSessions = $user->loginSessions()->notRevoked();

        if ($currentTokenId === null && $request->hasSession()) {
            $browserSessions->where('id', '!=', (string) $request->session()->get(
                'login_session_registry_id',
                $request->session()->getId(),
            ));
        }

        $revokedBrowserSessions = $browserSessions->update(['revoked_at' => now()]);

        $this->telemetry->browserSessionsRevoked(
            $user,
            $request,
            'other_sessions',
            $revokedBrowserSessions,
            reason: $reason->value,
            anonymous: $anonymous,
        );
        $this->invalidatePendingSecurityOperations($user);
        $user->invalidateRememberedLogin();
    }

    public function revokeAll(
        User $user,
        TokenRevokeReason $reason = TokenRevokeReason::LOGOUT_ALL,
        bool $anonymous = false,
    ): void {
        $this->mobileTokens->revokeAll($user, $reason, $anonymous);
        $user->tokens()->delete();
        $revokedBrowserSessions = $user->loginSessions()->notRevoked()->update(['revoked_at' => now()]);
        $this->telemetry->browserSessionsRevoked(
            $user,
            app()->bound('request') ? request() : null,
            'all_sessions',
            $revokedBrowserSessions,
            reason: $reason->value,
            anonymous: $anonymous,
        );
        $this->invalidatePendingSecurityOperations($user);
        $user->invalidateRememberedLogin();
    }

    public function invalidatePendingSecurityOperations(User $user): void
    {
        AccountActionToken::query()
            ->where('user_uuid', $user->uuid)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        AuthChallenge::query()
            ->where('user_uuid', $user->uuid)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        TwoFactorSetup::query()
            ->where('user_uuid', $user->uuid)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        TwoFactorCode::query()
            ->where('user_uuid', $user->uuid)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
