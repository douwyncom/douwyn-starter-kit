<?php

namespace App\Services\Auth;

use App\Data\Auth\IssuedTwoFactorSetup;
use App\Enums\TwoFactorMethod;
use App\Models\TwoFactorSetup;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use App\Support\EmailTwoFactor;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TwoFactorSetupService
{
    private const string CURRENT_EMAIL_PURPOSE = 'account_security.current';

    private const int SETUP_LIFETIME_MINUTES = 10;

    private const int MAX_SETUP_ATTEMPTS = 5;

    public function __construct(
        private readonly AuthSignature $authSignature,
        private readonly AccessRevocationService $revocation,
        private readonly SecurityTelemetry $telemetry,
    ) {}

    public function status(User $user): array
    {
        $user->refresh();
        $pendingSetup = TwoFactorSetup::query()
            ->where('user_uuid', $user->uuid)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        return [
            'enabled' => $user->hasEnabledTwoFactor(),
            'method' => ($user->two_factor_method ?? TwoFactorMethod::NONE)->value,
            'confirmed_at' => $user->two_factor_confirmed_at,
            'enabled_at' => $user->two_factor_enabled_at,
            'recovery_codes_remaining' => count((array) ($user->two_factor_recovery_codes ?? [])),
            'pending_setup' => $pendingSetup ? [
                'method' => $pendingSetup->method->value,
                'expires_at' => $pendingSetup->expires_at,
            ] : null,
        ];
    }

    public function startApp(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): IssuedTwoFactorSetup {
        return $this->startSetup(
            $user,
            TwoFactorMethod::APP,
            $currentPassword,
            $otp,
            $recoveryCode,
        );
    }

    public function startEmail(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): IssuedTwoFactorSetup {
        $rateKey = $this->setupEmailRateKey($user);

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            throw ValidationException::withMessages([
                'email' => [__('Too many verification emails. Please try again later.')],
            ]);
        }

        $issued = $this->startSetup(
            $user,
            TwoFactorMethod::EMAIL,
            $currentPassword,
            $otp,
            $recoveryCode,
        );

        try {
            $this->sendSetupEmail($issued->setup);
        } catch (Throwable $exception) {
            $issued->setup->forceFill(['consumed_at' => now()])->save();

            throw $exception;
        }

        return $issued;
    }

    public function resendEmail(User $user, string $plainTextToken): void
    {
        $setup = $this->findUsableSetup($user, $plainTextToken, TwoFactorMethod::EMAIL);

        $this->sendSetupEmail($setup);
    }

    public function sendCurrentEmailCode(User $user, string $currentPassword): void
    {
        $lockedUser = DB::transaction(function () use ($user, $currentPassword): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCurrentPassword($lockedUser, $currentPassword);

            if (! $lockedUser->hasEnabledTwoFactor(TwoFactorMethod::EMAIL)) {
                throw ValidationException::withMessages([
                    'two_factor' => [__('Email two-factor authentication is not enabled.')],
                ]);
            }

            return $lockedUser;
        });

        EmailTwoFactor::send(
            $lockedUser->uuid,
            $lockedUser->email,
            self::CURRENT_EMAIL_PURPOSE,
        );
    }

    public function confirmApp(
        User $user,
        string $plainTextToken,
        string $otp,
        Request $request,
    ): array {
        return $this->confirmSetup($user, $plainTextToken, TwoFactorMethod::APP, $otp, $request);
    }

    public function confirmEmail(
        User $user,
        string $plainTextToken,
        string $otp,
        Request $request,
    ): array {
        return $this->confirmSetup($user, $plainTextToken, TwoFactorMethod::EMAIL, $otp, $request);
    }

    public function disable(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
        Request $request,
    ): array {
        $result = DB::transaction(function () use (
            $user,
            $currentPassword,
            $otp,
            $recoveryCode,
            $request,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->assertStepUp($lockedUser, $currentPassword, $otp, $recoveryCode);

            $lockedUser->forceFill([
                'two_factor_method' => TwoFactorMethod::NONE,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_enabled_at' => null,
                'two_factor_last_used_timestamp' => null,
            ])->save();
            $lockedUser->withAccessToken($user->currentAccessToken());
            $this->revocation->revokeOtherAccess($lockedUser, $request);

            return ['user' => $lockedUser];
        });

        $this->telemetry->securityChanged(
            $result['user'],
            $request,
            'two_factor_disabled',
        );

        return $this->status($result['user']);
    }

    public function regenerateRecoveryCodes(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
        Request $request,
    ): array {
        $result = DB::transaction(function () use (
            $user,
            $currentPassword,
            $otp,
            $recoveryCode,
            $request,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedUser->hasEnabledTwoFactor()) {
                throw ValidationException::withMessages([
                    'two_factor' => [__('Enable two-factor authentication before generating recovery codes.')],
                ]);
            }

            $this->assertStepUp($lockedUser, $currentPassword, $otp, $recoveryCode);

            $recoveryCodes = TwoFactor::generateRecoveryCodes();
            $lockedUser->forceFill([
                'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes($recoveryCodes),
            ])->save();
            $lockedUser->withAccessToken($user->currentAccessToken());
            $this->revocation->revokeOtherAccess($lockedUser, $request);

            return [
                'user' => $lockedUser,
                'recovery_codes' => $recoveryCodes,
            ];
        });

        $this->telemetry->securityChanged(
            $result['user'],
            $request,
            'recovery_codes_regenerated',
            ['two_factor_method' => $result['user']->two_factor_method?->value],
        );

        return [
            'two_factor' => $this->status($result['user']),
            'recovery_codes' => $result['recovery_codes'],
        ];
    }

    private function startSetup(
        User $user,
        TwoFactorMethod $method,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): IssuedTwoFactorSetup {
        $plainTextToken = 'douwyn_2fs_'.Str::random(64);
        $secret = $method === TwoFactorMethod::APP
            ? TwoFactor::google2fa()->generateSecretKey()
            : null;

        $setup = DB::transaction(function () use (
            $user,
            $method,
            $currentPassword,
            $otp,
            $recoveryCode,
            $plainTextToken,
            $secret,
        ): TwoFactorSetup {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStepUp($lockedUser, $currentPassword, $otp, $recoveryCode);

            TwoFactorSetup::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return TwoFactorSetup::query()->create([
                'user_uuid' => $lockedUser->uuid,
                'token_hash' => TwoFactorSetup::tokenHash($plainTextToken),
                'method' => $method,
                'auth_signature' => $this->authSignature->for($lockedUser),
                'secret' => $secret,
                'expires_at' => now()->addMinutes(self::SETUP_LIFETIME_MINUTES),
            ]);
        });

        return new IssuedTwoFactorSetup(
            setup: $setup,
            plainTextToken: $plainTextToken,
            secret: $secret,
            otpauthUri: $secret
                ? TwoFactor::google2fa()->getQRCodeUrl(config('app.name'), $user->email, $secret)
                : null,
        );
    }

    private function confirmSetup(
        User $user,
        string $plainTextToken,
        TwoFactorMethod $expectedMethod,
        string $otp,
        Request $request,
    ): array {
        $tokenHash = TwoFactorSetup::tokenHash($plainTextToken);
        $result = DB::transaction(function () use (
            $user,
            $tokenHash,
            $expectedMethod,
            $otp,
            $request,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $setup = TwoFactorSetup::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();

            if (! $lockedUser
                || ! $setup
                || $setup->user_uuid !== $lockedUser->uuid
                || $setup->method !== $expectedMethod
                || $setup->consumed_at
                || $setup->expires_at->isPast()
                || $setup->attempts >= self::MAX_SETUP_ATTEMPTS) {
                return ['error' => 'expired'];
            }

            if (! hash_equals($setup->auth_signature, $this->authSignature->for($lockedUser))) {
                $setup->forceFill(['consumed_at' => now()])->save();

                return ['error' => 'expired'];
            }

            $verifiedTimestamp = null;
            $valid = match ($expectedMethod) {
                TwoFactorMethod::APP => ($verifiedTimestamp = TwoFactor::verifyTotpSecret(
                    (string) $setup->secret,
                    $otp,
                )) !== null,
                TwoFactorMethod::EMAIL => EmailTwoFactor::verify(
                    $lockedUser->uuid,
                    $otp,
                    $setup->emailPurpose(),
                ),
                default => false,
            };

            if (! $valid) {
                $attempts = $setup->attempts + 1;
                $setup->forceFill([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= self::MAX_SETUP_ATTEMPTS ? now() : null,
                ])->save();

                return ['error' => 'invalid'];
            }

            $recoveryCodes = TwoFactor::generateRecoveryCodes();
            $now = now();
            $lockedUser->forceFill([
                'two_factor_method' => $expectedMethod,
                'two_factor_secret' => $expectedMethod === TwoFactorMethod::APP ? $setup->secret : null,
                'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes($recoveryCodes),
                'two_factor_confirmed_at' => $now,
                'two_factor_enabled_at' => $now,
                'two_factor_last_used_timestamp' => $expectedMethod === TwoFactorMethod::APP
                    ? $verifiedTimestamp
                    : null,
                'email_verified_at' => $expectedMethod === TwoFactorMethod::EMAIL
                    ? ($lockedUser->email_verified_at ?? $now)
                    : $lockedUser->email_verified_at,
            ])->save();
            $setup->forceFill(['consumed_at' => $now])->save();
            $lockedUser->withAccessToken($user->currentAccessToken());
            $this->revocation->revokeOtherAccess($lockedUser, $request);

            return [
                'user' => $lockedUser,
                'recovery_codes' => $recoveryCodes,
            ];
        });

        if (($result['error'] ?? null) === 'expired') {
            $this->telemetry->twoFactorVerificationFailed(
                $user,
                $request,
                'account_security',
                'step_up',
                'setup_invalid_or_expired',
                $expectedMethod->value,
            );

            throw $this->expiredSetupException();
        }

        if (($result['error'] ?? null) === 'invalid') {
            $this->telemetry->twoFactorVerificationFailed(
                $user,
                $request,
                'account_security',
                'step_up',
                'invalid_code',
                $expectedMethod->value,
            );

            throw ValidationException::withMessages([
                'otp' => [__('Invalid or expired code.')],
            ]);
        }

        $this->telemetry->securityChanged(
            $result['user'],
            $request,
            'two_factor_enabled',
            ['two_factor_method' => $expectedMethod->value],
        );

        return [
            'two_factor' => $this->status($result['user']),
            'recovery_codes' => $result['recovery_codes'],
        ];
    }

    private function findUsableSetup(
        User $user,
        string $plainTextToken,
        TwoFactorMethod $method,
    ): TwoFactorSetup {
        $tokenHash = TwoFactorSetup::tokenHash($plainTextToken);
        $result = DB::transaction(function () use ($user, $tokenHash, $method): ?TwoFactorSetup {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $setup = TwoFactorSetup::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();

            $authStateMatches = $lockedUser && $setup
                ? hash_equals($setup->auth_signature, $this->authSignature->for($lockedUser))
                : false;

            if (! $lockedUser
                || ! $setup
                || $setup->user_uuid !== $lockedUser->uuid
                || $setup->method !== $method
                || $setup->consumed_at
                || $setup->expires_at->isPast()
                || ! $authStateMatches) {
                if ($setup
                    && $setup->user_uuid === $user->uuid
                    && $setup->method === $method
                    && ! $setup->consumed_at
                    && ($setup->expires_at->isPast() || ! $authStateMatches)) {
                    $setup->forceFill(['consumed_at' => now()])->save();
                }

                return null;
            }

            return $setup;
        });

        if (! $result) {
            throw $this->expiredSetupException();
        }

        return $result;
    }

    private function assertStepUp(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): void {
        $rateKey = "account-security-step-up:{$user->uuid}";

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages([
                'otp' => [__('Too many verification attempts. Please try again later.')],
            ]);
        }

        if (! Hash::check($currentPassword, $user->password)) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                'current_password' => [__('pages/account.password.current_password_helper')],
            ]);
        }

        if (! $user->hasEnabledTwoFactor()) {
            RateLimiter::clear($rateKey);

            return;
        }

        if (blank($otp) && blank($recoveryCode)) {
            throw ValidationException::withMessages([
                'otp' => [__('The current two-factor code or a recovery code is required.')],
            ]);
        }

        $valid = filled($recoveryCode)
            ? TwoFactor::consumeRecoveryCodeForLockedUser($user, (string) $recoveryCode)
            : match ($user->two_factor_method) {
                TwoFactorMethod::APP => TwoFactor::verifyAndConsumeTotpForLockedUser($user, (string) $otp),
                TwoFactorMethod::EMAIL => EmailTwoFactor::verify(
                    $user->uuid,
                    (string) $otp,
                    self::CURRENT_EMAIL_PURPOSE,
                ),
                default => false,
            };

        if (! $valid) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                filled($recoveryCode) ? 'recovery_code' : 'otp' => [__('Invalid or expired code.')],
            ]);
        }

        RateLimiter::clear($rateKey);
    }

    private function assertCurrentPassword(User $user, string $currentPassword): void
    {
        $rateKey = "account-security-password:{$user->uuid}";

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages([
                'current_password' => [__('Too many verification attempts. Please try again later.')],
            ]);
        }

        if (! Hash::check($currentPassword, $user->password)) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                'current_password' => [__('pages/account.password.current_password_helper')],
            ]);
        }

        RateLimiter::clear($rateKey);
    }

    private function sendSetupEmail(TwoFactorSetup $setup): void
    {
        $user = $setup->user()->firstOrFail();
        $rateKey = $this->setupEmailRateKey($user);

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            throw ValidationException::withMessages([
                'email' => [__('Too many verification emails. Please try again later.')],
            ]);
        }

        RateLimiter::hit($rateKey, 300);

        try {
            EmailTwoFactor::send($user->uuid, $user->email, $setup->emailPurpose());
        } catch (Throwable $exception) {
            RateLimiter::decrement($rateKey);

            throw $exception;
        }
    }

    private function setupEmailRateKey(User $user): string
    {
        return "account-security-email-setup:{$user->uuid}";
    }

    private function expiredSetupException(): ValidationException
    {
        return ValidationException::withMessages([
            'setup_token' => [__('The two-factor setup is invalid or expired.')],
        ]);
    }
}
