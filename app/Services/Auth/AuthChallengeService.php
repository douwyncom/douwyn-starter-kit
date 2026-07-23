<?php

namespace App\Services\Auth;

use App\Data\Auth\IssuedAuthChallenge;
use App\Data\Auth\MobileDeviceData;
use App\Enums\AuthCredentialType;
use App\Enums\TwoFactorMethod;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use App\Support\EmailTwoFactor;
use App\Support\TwoFactor;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthChallengeService
{
    public function __construct(
        private readonly AuthSignature $authSignature,
        private readonly DeviceFingerprint $deviceFingerprint,
        private readonly SecurityTelemetry $telemetry,
    ) {}

    public function issue(
        User $user,
        AuthCredentialType $credentialType,
        Request $request,
        ?string $deviceName = null,
        array $abilities = ['user:read', 'user:update'],
        ?MobileDeviceData $mobileDevice = null,
    ): IssuedAuthChallenge {
        $plainTextToken = 'douwyn_ch_'.Str::random(64);
        $expectedAuthSignature = $this->authSignature->for($user);
        $sessionBinding = null;

        if ($credentialType === AuthCredentialType::SESSION) {
            $sessionBinding = (string) $request->session()->get('auth_challenge_binding', Str::random(64));
            $request->session()->put('auth_challenge_binding', $sessionBinding);
        }

        $issued = DB::transaction(function () use (
            $user,
            $credentialType,
            $request,
            $deviceName,
            $abilities,
            $plainTextToken,
            $sessionBinding,
            $mobileDevice,
            $expectedAuthSignature,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->is_inactive
                || ! hash_equals($expectedAuthSignature, $this->authSignature->for($lockedUser))) {
                throw new HttpResponseException(response()->json([
                    'message' => __('Your authentication state changed. Please sign in again.'),
                    'code' => 'authentication_state_changed',
                ], 401));
            }

            AuthChallenge::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->where('credential_type', $credentialType->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $challenge = AuthChallenge::query()->create([
                'user_uuid' => $lockedUser->uuid,
                'token_hash' => AuthChallenge::tokenHash($plainTextToken),
                'credential_type' => $credentialType,
                'two_factor_method' => $lockedUser->two_factor_method->value,
                'auth_signature' => $this->authSignature->for($lockedUser),
                'session_id_hash' => $credentialType === AuthCredentialType::SESSION
                    ? hash('sha256', (string) $sessionBinding)
                    : null,
                'device_name' => $deviceName,
                'device_id_hash' => $mobileDevice?->deviceIdHash,
                'platform' => $mobileDevice?->platform->value,
                'app_version' => $mobileDevice?->appVersion,
                'abilities' => $abilities,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'expires_at' => now()->addMinutes(5),
            ]);

            return [
                'challenge' => $challenge,
                'email' => $lockedUser->email,
            ];
        });
        /** @var AuthChallenge $challenge */
        $challenge = $issued['challenge'];

        if ($challenge->two_factor_method === TwoFactorMethod::EMAIL->value) {
            try {
                EmailTwoFactor::send(
                    $challenge->user_uuid,
                    $issued['email'],
                    $challenge->emailPurpose(),
                    'auth_login',
                );
            } catch (Throwable $exception) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                throw $exception;
            }
        }

        $this->telemetry->twoFactorChallengeIssued(
            $user,
            $request,
            $this->channelFor($challenge),
            $credentialType,
            $challenge->two_factor_method,
        );

        return new IssuedAuthChallenge($challenge, $plainTextToken);
    }

    public function verify(
        string $plainTextToken,
        AuthCredentialType $expectedType,
        Request $request,
        ?string $otp,
        ?string $recoveryCode,
    ): AuthChallenge {
        $tokenHash = AuthChallenge::tokenHash($plainTextToken);
        $candidate = AuthChallenge::query()->where('token_hash', $tokenHash)->first();

        if (! $candidate) {
            $this->telemetry->twoFactorVerificationFailed(
                null,
                $request,
                $expectedType === AuthCredentialType::SESSION ? 'nuxt_session' : 'api_token',
                $expectedType,
                'challenge_invalid_or_expired',
            );

            throw $this->expiredChallengeException();
        }

        $result = DB::transaction(function () use (
            $candidate,
            $tokenHash,
            $expectedType,
            $request,
            $otp,
            $recoveryCode,
        ): array {
            $user = User::query()->whereKey($candidate->user_uuid)->lockForUpdate()->first();
            $challenge = AuthChallenge::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if (! $user || ! $challenge || $challenge->credential_type !== $expectedType) {
                return ['error' => 'expired'];
            }

            if ($challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
                return ['error' => 'expired'];
            }

            if ($user->is_inactive) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return ['error' => 'inactive'];
            }

            if (! $this->matchesAuthState($user, $challenge)
                || ! $this->matchesSession($challenge, $request)
                || ! $this->matchesMobileDevice($challenge, $request)) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return ['error' => 'expired'];
            }

            $valid = filled($recoveryCode)
                ? TwoFactor::consumeRecoveryCodeForLockedUser($user, (string) $recoveryCode)
                : $this->verifyOtp($user, $challenge, (string) $otp);

            if (! $valid) {
                $attempts = $challenge->attempts + 1;
                $challenge->forceFill([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= 5 ? now() : null,
                ])->save();

                return ['error' => 'invalid'];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['challenge' => $challenge->fresh('user.profile')];
        });

        if (($result['error'] ?? null) === 'inactive') {
            $this->recordVerificationFailure($candidate, $request, $expectedType, 'account_inactive');

            throw new HttpResponseException(response()->json([
                'message' => __('Account is inactive.'),
                'code' => 'account_inactive',
            ], 403));
        }

        if (($result['error'] ?? null) === 'expired') {
            $this->recordVerificationFailure($candidate, $request, $expectedType, 'challenge_invalid_or_expired');

            throw $this->expiredChallengeException();
        }

        if (($result['error'] ?? null) === 'invalid') {
            $this->recordVerificationFailure($candidate, $request, $expectedType, 'invalid_code');

            throw ValidationException::withMessages([
                (filled($recoveryCode) ? 'recovery_code' : 'otp') => [__('Invalid or expired code.')],
            ]);
        }

        $verifiedChallenge = $result['challenge'];
        $this->telemetry->twoFactorVerified(
            $verifiedChallenge->user,
            $request,
            $this->channelFor($verifiedChallenge),
            $expectedType,
            $verifiedChallenge->two_factor_method,
            filled($recoveryCode),
        );

        return $verifiedChallenge;
    }

    private function matchesAuthState(User $user, AuthChallenge $challenge): bool
    {
        $method = TwoFactorMethod::tryFrom($challenge->two_factor_method);

        return $method !== null
            && $user->hasEnabledTwoFactor($method)
            && hash_equals($challenge->auth_signature, $this->authSignature->for($user));
    }

    private function matchesSession(AuthChallenge $challenge, Request $request): bool
    {
        if ($challenge->credential_type !== AuthCredentialType::SESSION) {
            return true;
        }

        return $challenge->session_id_hash !== null
            && $request->session()->has('auth_challenge_binding')
            && hash_equals(
                $challenge->session_id_hash,
                hash('sha256', (string) $request->session()->get('auth_challenge_binding')),
            );
    }

    private function matchesMobileDevice(AuthChallenge $challenge, Request $request): bool
    {
        if ($challenge->device_id_hash === null) {
            return true;
        }

        $deviceId = trim((string) $request->input('device_id'));

        return $deviceId !== ''
            && hash_equals(
                $challenge->device_id_hash,
                $this->deviceFingerprint->hash($deviceId),
            );
    }

    private function verifyOtp(User $user, AuthChallenge $challenge, string $otp): bool
    {
        return match ($user->two_factor_method) {
            TwoFactorMethod::EMAIL => EmailTwoFactor::verify($user->uuid, $otp, $challenge->emailPurpose()),
            TwoFactorMethod::APP => TwoFactor::verifyAndConsumeTotpForLockedUser($user, $otp),
            default => false,
        };
    }

    private function recordVerificationFailure(
        AuthChallenge $challenge,
        Request $request,
        AuthCredentialType $credentialType,
        string $reason,
    ): void {
        $this->telemetry->twoFactorVerificationFailed(
            $challenge->user,
            $request,
            $this->channelFor($challenge),
            $credentialType,
            $reason,
            $challenge->two_factor_method,
        );
    }

    private function channelFor(AuthChallenge $challenge): string
    {
        return match (true) {
            $challenge->credential_type === AuthCredentialType::SESSION => 'nuxt_session',
            filled($challenge->device_id_hash) => 'mobile',
            default => 'legacy_token',
        };
    }

    private function expiredChallengeException(): ValidationException
    {
        return ValidationException::withMessages([
            'challenge_token' => [__('The authentication challenge is invalid or expired.')],
        ]);
    }
}
