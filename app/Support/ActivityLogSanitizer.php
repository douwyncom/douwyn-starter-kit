<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

class ActivityLogSanitizer
{
    private const array SENSITIVE_KEYS = [
        'access_token',
        'authorization',
        'challenge_token',
        'client_secret',
        'credential',
        'credentials',
        'current_password',
        'address_line1',
        'address_line2',
        'avatar_url',
        'bio',
        'birthdate',
        'city',
        'country',
        'device_id',
        'device_id_hash',
        'device_session_id',
        'email_code',
        'first_name',
        'gender',
        'last_name',
        'locale',
        'metadata',
        'otp',
        'new_password',
        'password',
        'password_confirmation',
        'phone',
        'postal_code',
        'recovery_code',
        'recovery_codes',
        'refresh_token',
        'remember_token',
        'rotation_response',
        'secret',
        'session_cookie',
        'session_id',
        'setup_token',
        'token',
        'token_hash',
        'totp',
        'timezone',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'otpauth_uri',
        'verification_code',
        'website',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    public static function sanitize(mixed $value): array
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        return self::sanitizeArray(is_array($value) ? $value : []);
    }

    private static function sanitizeArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $values[$key] = self::sanitizeArray($value);
            }
        }

        return $values;
    }
}
