<?php

namespace App\Services\Auth;

use App\Enums\AccountActionType;
use App\Enums\TokenRevokeReason;
use App\Enums\TwoFactorMethod;
use App\Models\AccountActionToken;
use App\Models\User;
use App\Notifications\ConfirmAccountEmailChange;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccountEmail;
use App\Services\Security\SecurityTelemetry;
use App\Support\EmailTwoFactor;
use App\Support\TwoFactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountLifecycleService
{
    private const string CURRENT_EMAIL_FACTOR_PURPOSE = 'account_security.current';

    public function __construct(
        private readonly AuthSignature $authSignature,
        private readonly AccessRevocationService $revocation,
        private readonly SecurityTelemetry $telemetry,
    ) {}

    public function emailStatus(User $user): array
    {
        $user->refresh();
        $pending = AccountActionToken::query()
            ->where('user_uuid', $user->uuid)
            ->where('type', AccountActionType::EMAIL_CHANGE)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $usable = $pending
            && hash_equals($pending->auth_signature, $this->authSignature->for($user))
            && hash_equals($pending->source_email_hash, AccountActionToken::emailHash($user->email));

        return [
            'email' => $user->email,
            'verified' => $user->email_verified_at !== null,
            'verified_at' => $user->email_verified_at,
            'pending_email' => $usable ? $pending->target_email : null,
            'pending_expires_at' => $usable ? $pending->expires_at : null,
        ];
    }

    public function sendEmailVerification(User $user): void
    {
        $issued = $this->issue(
            $user,
            AccountActionType::EMAIL_VERIFICATION,
            (int) config('auth_lifecycle.email_verification_minutes'),
            'douwyn_ev_',
            $user->email,
        );

        if ($issued === null) {
            return;
        }

        $issued['user']->notify(new VerifyAccountEmail($issued['plain_text_token']));
    }

    public function verifyEmail(string $plainTextToken): User
    {
        $type = AccountActionType::EMAIL_VERIFICATION;
        $tokenHash = AccountActionToken::tokenHash($plainTextToken, $type);
        $userUuid = AccountActionToken::query()
            ->where('token_hash', $tokenHash)
            ->where('type', $type)
            ->value('user_uuid');

        if (! is_string($userUuid)) {
            throw $this->invalidTokenException();
        }

        $result = DB::transaction(function () use ($userUuid, $tokenHash, $type): ?User {
            $user = User::query()->whereKey($userUuid)->lockForUpdate()->first();
            $token = AccountActionToken::query()
                ->where('token_hash', $tokenHash)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (! $user || ! $this->isUsable($token, $user, $type)) {
                return null;
            }

            $emailHash = AccountActionToken::emailHash($user->email);

            if (! hash_equals((string) $token->target_email_hash, $emailHash)) {
                $token->forceFill(['consumed_at' => now()])->save();

                return null;
            }

            $now = now();
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? $now])->save();
            $token->forceFill(['consumed_at' => $now])->save();

            return $user;
        });

        if (! $result) {
            throw $this->invalidTokenException();
        }

        return $result;
    }

    public function sendPasswordReset(string $email): void
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->where('is_inactive', false)
            ->first();

        if (! $user) {
            // Preserve a comparable token-processing path without revealing whether
            // the normalized address belongs to an account.
            AccountActionToken::tokenHash('douwyn_pr_'.Str::random(64), AccountActionType::PASSWORD_RESET);

            return;
        }

        try {
            $issued = $this->issue(
                $user,
                AccountActionType::PASSWORD_RESET,
                (int) config('auth_lifecycle.password_reset_minutes'),
                'douwyn_pr_',
            );

            if ($issued !== null) {
                $issued['user']->notify(new ResetAccountPassword($issued['plain_text_token']));
            }
        } catch (Throwable $exception) {
            // A delivery or queue outage must not reveal that this address belongs
            // to an account. The public endpoint always keeps its generic response.
            report($exception);
        }
    }

    public function resetPassword(string $plainTextToken, string $password, Request $request): User
    {
        $type = AccountActionType::PASSWORD_RESET;
        $tokenHash = AccountActionToken::tokenHash($plainTextToken, $type);
        $userUuid = AccountActionToken::query()
            ->where('token_hash', $tokenHash)
            ->where('type', $type)
            ->value('user_uuid');

        if (! is_string($userUuid)) {
            throw $this->invalidTokenException();
        }

        $guard = Auth::guard('web');
        $sessionBelongsToUser = $request->hasSession()
            && ((string) $guard->id() === $userUuid
                || (string) $request->session()->get($guard->getName()) === $userUuid);

        $result = DB::transaction(function () use ($userUuid, $tokenHash, $type, $password): ?User {
            $user = User::query()->whereKey($userUuid)->lockForUpdate()->first();
            $token = AccountActionToken::query()
                ->where('token_hash', $tokenHash)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (! $user || ! $this->isUsable($token, $user, $type)) {
                return null;
            }

            $user->forceFill(['password' => $password])->save();
            $token->forceFill(['consumed_at' => now()])->save();
            $this->revocation->revokeAll(
                $user,
                TokenRevokeReason::PASSWORD_CHANGED,
                anonymous: true,
            );

            return $user;
        });

        if (! $result) {
            throw $this->invalidTokenException();
        }

        if ($sessionBelongsToUser) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Auth::forgetGuards();
        }

        $this->telemetry->passwordChanged(
            $result,
            $request,
            'password_reset',
            anonymous: true,
        );

        return $result;
    }

    public function requestEmailChange(
        User $user,
        string $newEmail,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): void {
        $normalizedEmail = $this->normalizeEmail($newEmail);
        $plainTextToken = 'douwyn_ec_'.Str::random(64);

        $result = DB::transaction(function () use (
            $user,
            $normalizedEmail,
            $currentPassword,
            $otp,
            $recoveryCode,
            $plainTextToken,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->email_verified_at === null) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.errors.email_verification_required')],
                ]);
            }

            $this->assertStepUp($lockedUser, $currentPassword, $otp, $recoveryCode);
            $this->assertEmailAvailable($lockedUser, $normalizedEmail);

            AccountActionToken::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->where('type', AccountActionType::EMAIL_CHANGE)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            AccountActionToken::query()->create([
                'user_uuid' => $lockedUser->uuid,
                'type' => AccountActionType::EMAIL_CHANGE,
                'token_hash' => AccountActionToken::tokenHash(
                    $plainTextToken,
                    AccountActionType::EMAIL_CHANGE,
                ),
                'auth_signature' => $this->authSignature->for($lockedUser),
                'source_email_hash' => AccountActionToken::emailHash($lockedUser->email),
                'target_email' => $normalizedEmail,
                'target_email_hash' => AccountActionToken::emailHash($normalizedEmail),
                'expires_at' => now()->addMinutes((int) config('auth_lifecycle.email_change_minutes')),
            ]);

            return [
                'email' => $normalizedEmail,
                'token' => $plainTextToken,
                'locale' => $lockedUser->preferredLocale(),
            ];
        });

        Notification::route('mail', $result['email'])
            ->notify((new ConfirmAccountEmailChange($result['token']))
                ->locale($result['locale']));
    }

    public function confirmEmailChange(User $user, string $plainTextToken, Request $request): User
    {
        $type = AccountActionType::EMAIL_CHANGE;
        $tokenHash = AccountActionToken::tokenHash($plainTextToken, $type);

        try {
            $result = DB::transaction(function () use ($user, $tokenHash, $type, $request): ?User {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
                $token = AccountActionToken::query()
                    ->where('token_hash', $tokenHash)
                    ->where('type', $type)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedUser
                    || ! $token
                    || $token->user_uuid !== $lockedUser->uuid
                    || ! $this->isUsable($token, $lockedUser, $type)) {
                    return null;
                }

                $newEmail = $this->normalizeEmail((string) $token->target_email);

                if (! hash_equals(
                    (string) $token->target_email_hash,
                    AccountActionToken::emailHash($newEmail),
                )) {
                    $token->forceFill(['consumed_at' => now()])->save();

                    return null;
                }

                $this->assertEmailAvailable($lockedUser, $newEmail);
                $lockedUser->withAccessToken($user->currentAccessToken());
                $now = now();
                $lockedUser->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => $now,
                ])->save();
                $token->forceFill(['consumed_at' => $now])->save();
                $this->revocation->revokeOtherAccess(
                    $lockedUser,
                    $request,
                    TokenRevokeReason::SECURITY_CHANGED,
                );

                return $lockedUser;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => [__('auth.errors.email_taken')],
            ]);
        }

        if (! $result) {
            throw $this->invalidTokenException();
        }

        if ($request->hasSession()
            && Auth::guard('web')->id() === $result->getAuthIdentifier()) {
            Auth::guard('web')->setUser($result);
            Auth::forgetGuards();
        }

        $this->telemetry->securityChanged($result, $request, 'email_changed');

        return $result;
    }

    private function issue(
        User $user,
        AccountActionType $type,
        int $lifetimeMinutes,
        string $prefix,
        ?string $targetEmail = null,
    ): ?array {
        $plainTextToken = $prefix.Str::random(64);

        return DB::transaction(function () use (
            $user,
            $type,
            $lifetimeMinutes,
            $plainTextToken,
            $targetEmail,
        ): ?array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->is_inactive) {
                return null;
            }

            if ($type === AccountActionType::EMAIL_VERIFICATION && $lockedUser->email_verified_at !== null) {
                return null;
            }

            AccountActionToken::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->where('type', $type)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $normalizedTarget = $type === AccountActionType::EMAIL_VERIFICATION
                ? $this->normalizeEmail($lockedUser->email)
                : ($targetEmail !== null ? $this->normalizeEmail($targetEmail) : null);
            $token = AccountActionToken::query()->create([
                'user_uuid' => $lockedUser->uuid,
                'type' => $type,
                'token_hash' => AccountActionToken::tokenHash($plainTextToken, $type),
                'auth_signature' => $this->authSignature->for($lockedUser),
                'source_email_hash' => AccountActionToken::emailHash($lockedUser->email),
                'target_email' => $normalizedTarget,
                'target_email_hash' => $normalizedTarget !== null
                    ? AccountActionToken::emailHash($normalizedTarget)
                    : null,
                'expires_at' => now()->addMinutes($lifetimeMinutes),
            ]);

            return [
                'user' => $lockedUser,
                'token' => $token,
                'plain_text_token' => $plainTextToken,
            ];
        });
    }

    private function isUsable(
        ?AccountActionToken $token,
        User $user,
        AccountActionType $type,
    ): bool {
        if (! $token
            || $token->user_uuid !== $user->uuid
            || $token->type !== $type
            || $token->consumed_at
            || $token->expires_at->isPast()
            || $user->is_inactive) {
            return false;
        }

        $authStateMatches = hash_equals($token->auth_signature, $this->authSignature->for($user));
        $emailMatches = hash_equals(
            $token->source_email_hash,
            AccountActionToken::emailHash($user->email),
        );

        if (! $authStateMatches || ! $emailMatches) {
            $token->forceFill(['consumed_at' => now()])->save();

            return false;
        }

        return true;
    }

    private function assertStepUp(
        User $user,
        string $currentPassword,
        ?string $otp,
        ?string $recoveryCode,
    ): void {
        $rateKey = "account-email-change-step-up:$user->uuid";

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages([
                'otp' => [__('auth.errors.verification_attempts_throttled')],
            ]);
        }

        if (! Hash::check($currentPassword, $user->password)) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                'current_password' => [__('auth.errors.current_password_incorrect')],
            ]);
        }

        if (! $user->hasEnabledTwoFactor()) {
            RateLimiter::clear($rateKey);

            return;
        }

        if (blank($otp) && blank($recoveryCode)) {
            throw ValidationException::withMessages([
                'otp' => [__('auth.errors.current_factor_required')],
            ]);
        }

        $valid = filled($recoveryCode)
            ? TwoFactor::consumeRecoveryCodeForLockedUser($user, (string) $recoveryCode)
            : match ($user->two_factor_method) {
                TwoFactorMethod::APP => TwoFactor::verifyAndConsumeTotpForLockedUser($user, (string) $otp),
                TwoFactorMethod::EMAIL => EmailTwoFactor::verify(
                    $user->uuid,
                    (string) $otp,
                    self::CURRENT_EMAIL_FACTOR_PURPOSE,
                ),
                default => false,
            };

        if (! $valid) {
            RateLimiter::hit($rateKey, 300);

            throw ValidationException::withMessages([
                filled($recoveryCode) ? 'recovery_code' : 'otp' => [__('auth.errors.invalid_or_expired_code')],
            ]);
        }

        RateLimiter::clear($rateKey);
    }

    private function assertEmailAvailable(User $user, string $email): void
    {
        if (hash_equals($this->normalizeEmail($user->email), $email)
            || User::query()
                ->whereKeyNot($user->getKey())
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('auth.errors.email_taken')],
            ]);
        }
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function invalidTokenException(): ValidationException
    {
        return ValidationException::withMessages([
            'token' => [__('auth.errors.account_action_token_invalid')],
        ]);
    }
}
