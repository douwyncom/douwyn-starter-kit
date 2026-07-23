<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CredentialAuthenticator
{
    private const string DUMMY_PASSWORD_HASH = '$2y$12$E570YWU.j7rrZqVjLo/Kbe2F59RVGxZ1a3F5ZtBfWldnzk21KrQNa';

    public function __construct(private readonly SecurityTelemetry $telemetry) {}

    public function authenticate(
        string $email,
        string $password,
        ?Request $request = null,
        string $channel = 'api_token',
    ): User {
        $user = User::query()->where('email', $email)->first();
        $passwordIsValid = Hash::check($password, (string) ($user?->password ?? self::DUMMY_PASSWORD_HASH));

        if (! $user || ! $passwordIsValid) {
            $this->telemetry->loginFailed(
                $user,
                $request,
                $channel,
                'invalid_credentials',
                $email,
            );

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->is_inactive) {
            $this->telemetry->loginFailed(
                $user,
                $request,
                $channel,
                'account_inactive',
                $email,
            );

            throw new HttpResponseException(response()->json([
                'message' => __('Account is inactive.'),
                'code' => 'account_inactive',
            ], 403));
        }

        if (config('hashing.rehash_on_login', true) && Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }
}
