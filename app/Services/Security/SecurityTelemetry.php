<?php

namespace App\Services\Security;

use App\Enums\ApiClientType;
use App\Enums\AuthCredentialType;
use App\Enums\SecurityEvent;
use App\Enums\TokenRevokeReason;
use App\Models\ApiDeviceSession;
use App\Models\PersonalAccessToken as AppPersonalAccessToken;
use App\Models\User;
use App\Support\ActivityLogSanitizer;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class SecurityTelemetry
{
    public const string LOG_NAME = 'security';

    public const string CHANNEL_ATTRIBUTE = 'security_telemetry_channel';

    public function loginFailed(
        ?User $user,
        ?Request $request,
        string $channel,
        string $reason,
        ?string $identity = null,
    ): void {
        $this->record(SecurityEvent::LOGIN_FAILED, $user, $request, [
            'channel' => $channel,
            'reason' => $reason,
            'identity_hash' => $this->hashNullable($identity),
        ], anonymous: true);
    }

    public function loginSucceeded(
        User $user,
        ?Request $request,
        string $channel,
        AuthCredentialType|string $credentialType,
        bool $twoFactor = false,
    ): void {
        $this->record(SecurityEvent::LOGIN_SUCCEEDED, $user, $request, [
            'channel' => $channel,
            'credential_type' => $credentialType instanceof AuthCredentialType
                ? $credentialType->value
                : $credentialType,
            'two_factor' => $twoFactor,
        ], anonymous: true);
    }

    public function twoFactorChallengeIssued(
        User $user,
        ?Request $request,
        string $channel,
        AuthCredentialType|string $credentialType,
        string $method,
    ): void {
        $this->record(SecurityEvent::TWO_FACTOR_CHALLENGE_ISSUED, $user, $request, [
            'channel' => $channel,
            'credential_type' => $credentialType instanceof AuthCredentialType
                ? $credentialType->value
                : $credentialType,
            'two_factor_method' => $method,
        ], anonymous: true);
    }

    public function twoFactorVerificationFailed(
        ?User $user,
        ?Request $request,
        string $channel,
        AuthCredentialType|string $credentialType,
        string $reason,
        ?string $method = null,
    ): void {
        $this->record(SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED, $user, $request, [
            'channel' => $channel,
            'credential_type' => $credentialType instanceof AuthCredentialType
                ? $credentialType->value
                : $credentialType,
            'reason' => $reason,
            'two_factor_method' => $method,
        ], anonymous: true);
    }

    public function twoFactorVerified(
        User $user,
        ?Request $request,
        string $channel,
        AuthCredentialType|string $credentialType,
        string $method,
        bool $usedRecoveryCode,
    ): void {
        $this->record(SecurityEvent::TWO_FACTOR_VERIFIED, $user, $request, [
            'channel' => $channel,
            'credential_type' => $credentialType instanceof AuthCredentialType
                ? $credentialType->value
                : $credentialType,
            'two_factor_method' => $method,
            'used_recovery_code' => $usedRecoveryCode,
        ], anonymous: true);
    }

    public function refreshTokenReused(
        ApiDeviceSession $deviceSession,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->record(SecurityEvent::REFRESH_TOKEN_REUSED, $deviceSession->user, null, [
            'channel' => 'mobile',
            'device_session_hash' => $this->hashNullable((string) $deviceSession->getKey()),
            'platform' => $deviceSession->platform?->value,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $this->hashNullable($userAgent),
        ], anonymous: true);
    }

    public function browserSessionsRevoked(
        User $user,
        ?Request $request,
        string $scope,
        int $revokedCount = 1,
        ?string $sessionId = null,
        string $reason = 'user_revoked',
        bool $anonymous = false,
    ): void {
        if ($revokedCount < 1) {
            return;
        }

        $this->record(SecurityEvent::BROWSER_SESSION_REVOKED, $user, $request, [
            'channel' => $this->requestChannel($request),
            'scope' => $scope,
            'reason' => $reason,
            'revoked_count' => $revokedCount,
            'session_hash' => $this->hashNullable($sessionId),
        ], anonymous: $anonymous);
    }

    public function deviceSessionRevoked(
        ApiDeviceSession $deviceSession,
        TokenRevokeReason $reason,
        ?Request $request = null,
        bool $anonymous = false,
    ): void {
        $this->record(SecurityEvent::DEVICE_SESSION_REVOKED, $deviceSession->user, $request, [
            'channel' => $this->requestChannel($request, 'mobile'),
            'device_session_hash' => $this->hashNullable((string) $deviceSession->getKey()),
            'platform' => $deviceSession->platform?->value,
            'reason' => $reason->value,
        ], anonymous: $anonymous);
    }

    public function passwordChanged(
        User $user,
        ?Request $request,
        string $channel,
        bool $anonymous = false,
    ): void {
        $this->record(SecurityEvent::PASSWORD_CHANGED, $user, $request, [
            'channel' => $channel,
        ], anonymous: $anonymous);
    }

    /** @param array<string, bool|int|string|null> $context */
    public function securityChanged(
        User $user,
        ?Request $request,
        string $change,
        array $context = [],
    ): void {
        $this->record(SecurityEvent::SECURITY_CHANGED, $user, $request, [
            'channel' => $this->requestChannel($request),
            'change' => $change,
            ...$context,
        ]);
    }

    public function sensitiveActionChallengeIssued(
        User $user,
        ?Request $request,
        string $action,
        ?string $subject = null,
    ): void {
        $this->record(SecurityEvent::SENSITIVE_ACTION_CHALLENGE_ISSUED, $user, $request, [
            'channel' => $this->requestChannel($request),
            'action' => $action,
            'subject_hash' => $this->hashNullable($subject),
            'two_factor_method' => 'email',
        ]);
    }

    public function sensitiveActionAuthorized(
        User $user,
        ?Request $request,
        string $action,
        ?string $subject = null,
        ?string $factor = null,
    ): void {
        $this->record(SecurityEvent::SENSITIVE_ACTION_AUTHORIZED, $user, $request, [
            'channel' => $this->requestChannel($request),
            'action' => $action,
            'subject_hash' => $this->hashNullable($subject),
            'verified_factor' => $factor ?? 'password',
        ]);
    }

    public function sensitiveActionAuthorizationFailed(
        User $user,
        ?Request $request,
        string $action,
        ?string $subject,
        string $reason,
        ?string $factor = null,
    ): void {
        $this->record(SecurityEvent::SENSITIVE_ACTION_AUTHORIZATION_FAILED, $user, $request, [
            'channel' => $this->requestChannel($request),
            'action' => $action,
            'subject_hash' => $this->hashNullable($subject),
            'reason' => $reason,
            'attempted_factor' => $factor,
        ]);
    }

    /** @param array<string, bool|int|string|null> $properties */
    private function record(
        SecurityEvent $event,
        ?User $subject,
        ?Request $request,
        array $properties,
        bool $anonymous = false,
    ): void {
        try {
            $logger = activity(self::LOG_NAME)
                ->event($event->value)
                ->withProperties(ActivityLogSanitizer::sanitize([
                    ...$properties,
                    ...$this->requestContext($request),
                ]));

            if ($subject) {
                $logger->performedOn($subject);
            }

            $causer = auth()->user();

            if (! $anonymous && $causer instanceof User) {
                $logger->causedBy($causer);
            } else {
                $logger->causedByAnonymous();
            }

            $logger->log("security.$event->value");
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array{ip_address?: string, request_id?: string, user_agent_hash?: string} */
    private function requestContext(?Request $request): array
    {
        if (! $request) {
            return [];
        }

        return array_filter([
            'ip_address' => $request->ip(),
            'request_id' => is_string($request->attributes->get('request_id'))
                ? $request->attributes->get('request_id')
                : null,
            'user_agent_hash' => $this->hashNullable($request->userAgent()),
        ], fn (?string $value): bool => filled($value));
    }

    private function requestChannel(?Request $request, string $default = 'system'): string
    {
        if (! $request) {
            return $default;
        }

        $explicitChannel = $request->attributes->get(self::CHANNEL_ATTRIBUTE);

        if (is_string($explicitChannel) && in_array($explicitChannel, [
            'filament',
            'mobile',
            'api_token',
            'nuxt_session',
            'system',
        ], true)) {
            return $explicitChannel;
        }

        $accessToken = $request->user()?->currentAccessToken();

        return match (true) {
            $request->is('admin/*') => 'filament',
            $request->is('livewire/*') => 'filament',
            ! $request->is('api/*') && Filament::getCurrentPanel()?->getId() === 'admin' => 'filament',
            $request->is('api/v1/auth/token/*') => 'mobile',
            $accessToken instanceof AppPersonalAccessToken
                && $accessToken->client_type === ApiClientType::MOBILE => 'mobile',
            $accessToken instanceof PersonalAccessToken => 'api_token',
            $request->is('api/*') => 'nuxt_session',
            default => $default,
        };
    }

    private function hashNullable(?string $value): ?string
    {
        $value = Str::lower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
