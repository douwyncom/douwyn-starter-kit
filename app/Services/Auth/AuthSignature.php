<?php

namespace App\Services\Auth;

use App\Models\User;

class AuthSignature
{
    public function for(User $user): string
    {
        $method = $user->two_factor_method?->value ?? 'none';
        $payload = implode('|', [
            mb_strtolower(trim($user->email)),
            $user->password,
            $method,
            $user->two_factor_confirmed_at?->getTimestamp() ?? '',
            $user->two_factor_enabled_at?->getTimestamp() ?? '',
            hash('sha256', (string) ($user->two_factor_secret ?? '')),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
