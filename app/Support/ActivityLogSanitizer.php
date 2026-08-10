<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

class ActivityLogSanitizer
{
    /** Matches the configurable credential-prefix contract used by modules. */
    private const string CREDENTIAL_PATTERN = '/(?<![A-Za-z0-9_-])[A-Z0-9]{3,12}\.[A-Za-z0-9_-]{12,32}\.[A-Za-z0-9_-]{32,64}(?![A-Za-z0-9_-])/';

    private const array SENSITIVE_KEYS = [
        'access_token',
        'activation_token',
        'api_key',
        'authorization',
        'bearer_token',
        'challenge_token',
        'client_secret',
        'credential',
        'credential_digest',
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
        'key',
        'last_name',
        'license_key',
        'locale',
        'metadata',
        'otp',
        'one_time_password',
        'new_password',
        'password',
        'password_confirmation',
        'password_hash',
        'phone',
        'postal_code',
        'private_key',
        'raw_credential',
        'raw_secret',
        'recovery_code',
        'recovery_codes',
        'refresh_token',
        'remember_token',
        'rotation_response',
        'secret',
        'secret_key',
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
            if (self::isSensitiveKey((string) $key)) {
                $values[$key] = '[REDACTED]';

                continue;
            }

            $values[$key] = self::sanitizeValue($value);
        }

        return $values;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            return self::sanitizeArray($value);
        }

        if (is_string($value) && preg_match(self::CREDENTIAL_PATTERN, $value) === 1) {
            return '[REDACTED]';
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key));
        $normalized = (string) preg_replace(
            '/[^a-z0-9]+/',
            '_',
            mb_strtolower($normalized),
        );
        $normalized = trim($normalized, '_');

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return str_ends_with($normalized, '_password')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_credential')
            || str_ends_with($normalized, '_token');
    }
}
