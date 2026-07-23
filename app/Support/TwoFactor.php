<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class TwoFactor
{
    public static function google2fa(): Google2FA
    {
        return app(Google2FA::class);
    }

    public static function generateRecoveryCodes(int $count = 10): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(10).'-'.Str::random(10)))
            ->values()
            ->all();
    }

    public static function hashRecoveryCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn ($code): string => 'sha256:'.hash('sha256', self::normalizeRecoveryCode($code)))
            ->values()
            ->all();
    }

    public static function consumeRecoveryCode(User $user, string $input): bool
    {
        return DB::transaction(function () use ($user, $input): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return $lockedUser
                ? self::consumeRecoveryCodeForLockedUser($lockedUser, $input)
                : false;
        });
    }

    public static function consumeRecoveryCodeForLockedUser(User $user, string $input): bool
    {
        $needle = self::normalizeRecoveryCode($input);

        if ($needle === '') {
            return false;
        }

        $codes = (array) ($user->two_factor_recovery_codes ?? []);

        foreach ($codes as $index => $code) {
            $matches = str_starts_with((string) $code, 'sha256:')
                ? hash_equals((string) $code, 'sha256:'.hash('sha256', $needle))
                : hash_equals(self::normalizeRecoveryCode($code), $needle);

            if ($matches) {
                unset($codes[$index]);
                $user->two_factor_recovery_codes = array_values($codes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    private static function normalizeRecoveryCode(mixed $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
    }

    public static function verifyAndConsumeTotp(User $user, string $otp): bool
    {
        return DB::transaction(function () use ($user, $otp): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return $lockedUser
                ? self::verifyAndConsumeTotpForLockedUser($lockedUser, $otp)
                : false;
        });
    }

    public static function verifyAndConsumeTotpForLockedUser(User $user, string $otp): bool
    {
        if (blank($user->two_factor_secret)) {
            return false;
        }

        $lastUsedTimestamp = (int) ($user->two_factor_last_used_timestamp ?? 0);
        $verifiedTimestamp = self::verifyTotpSecret(
            (string) $user->two_factor_secret,
            $otp,
            $lastUsedTimestamp,
        );

        if ($verifiedTimestamp === null) {
            return false;
        }

        $user->forceFill([
            'two_factor_last_used_timestamp' => $verifiedTimestamp,
        ])->save();

        return true;
    }

    public static function verifyTotpSecret(string $secret, string $otp, int $lastUsedTimestamp = 0): ?int
    {
        $otp = preg_replace('/\s+/', '', $otp);

        if (! preg_match('/^\d{6}$/D', $otp)) {
            return null;
        }

        try {
            $verifiedTimestamp = self::google2fa()->verifyKeyNewer(
                $secret,
                $otp,
                $lastUsedTimestamp,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_int($verifiedTimestamp) && $verifiedTimestamp > $lastUsedTimestamp
            ? $verifiedTimestamp
            : null;
    }
}
