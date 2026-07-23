<?php

namespace App\Support;

use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailTwoFactor
{
    public static function send(
        string $userUuid,
        string $email,
        string $purpose,
        ?string $rateScope = null,
    ): void {
        $key = '2fa:send:email:'.($rateScope ?: $purpose).":$userUuid";

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'message' => __('Too many requests. Try again in :s seconds.', ['s' => $seconds]),
            ]);
        }

        $code = (string) random_int(100000, 999999);
        RateLimiter::hit($key, 300);
        $record = null;

        try {
            $record = DB::transaction(function () use ($userUuid, $email, $purpose, $code): TwoFactorCode {
                User::query()->whereKey($userUuid)->lockForUpdate()->firstOrFail();

                $active = TwoFactorCode::query()
                    ->where('user_uuid', $userUuid)
                    ->where('channel', 'email')
                    ->where('purpose', $purpose)
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->first();

                if ($active && $active->created_at->gt(now()->subSeconds(20))) {
                    throw ValidationException::withMessages([
                        'message' => __('Please wait a moment before requesting another code.'),
                    ]);
                }

                TwoFactorCode::query()
                    ->where('user_uuid', $userUuid)
                    ->where('channel', 'email')
                    ->where('purpose', $purpose)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                return TwoFactorCode::query()->create([
                    'user_uuid' => $userUuid,
                    'channel' => 'email',
                    'sent_to' => $email,
                    'purpose' => $purpose,
                    'code_hash' => Hash::make($code),
                    'expires_at' => now()->addMinutes(5),
                ]);

            });

            Mail::raw("Your verification code is: $code", function ($message) use ($email): void {
                $message->to($email)->subject('Your verification code');
            });
        } catch (Throwable $exception) {
            RateLimiter::decrement($key);

            if ($record instanceof TwoFactorCode) {
                $record->delete();
            }

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            throw ValidationException::withMessages([
                'message' => __('Unable to send the verification code. Please try again.'),
            ]);
        }
    }

    public static function verify(string $userUuid, string $input, string $purpose): bool
    {
        return DB::transaction(function () use ($userUuid, $input, $purpose): bool {
            $record = TwoFactorCode::query()
                ->where('user_uuid', $userUuid)
                ->where('channel', 'email')
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $record || $record->expires_at->isPast() || ! Hash::check($input, $record->code_hash)) {
                return false;
            }

            $record->update(['consumed_at' => now()]);

            return true;
        });
    }
}
