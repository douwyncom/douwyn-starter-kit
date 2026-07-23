<?php

namespace App\Services\Auth;

use RuntimeException;

class DeviceFingerprint
{
    public function hash(string $deviceId): string
    {
        $key = $this->key();

        return hash_hmac('sha256', mb_strtolower(trim($deviceId)), $key);
    }

    private function key(): string
    {
        $key = (string) config('auth_tokens.device_hash_key', config('app.key'));

        if ($key === '') {
            throw new RuntimeException('An application key is required to hash mobile device identifiers.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(mb_substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
