<?php

namespace App\Services\Auth;

use App\Models\User;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TokenIssuer
{
    public function __construct(
        private readonly AuthSignature $authSignature,
        private readonly TokenAbilityRegistry $abilities,
    ) {}

    public function issue(
        User $user,
        string $deviceName,
        Request $request,
        ?array $abilities = null,
    ): array {
        $abilities ??= $this->abilities->abilitiesFor(TokenAbilityProfile::LEGACY);
        $expectedAuthSignature = $this->authSignature->for($user);

        return DB::transaction(function () use (
            $user,
            $deviceName,
            $abilities,
            $expectedAuthSignature,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->is_inactive
                || ! hash_equals($expectedAuthSignature, $this->authSignature->for($lockedUser))) {
                throw new HttpResponseException(response()->json([
                    'message' => __('auth.errors.authentication_state_changed'),
                    'code' => 'authentication_state_changed',
                ], 401));
            }

            $expirationMinutes = (int) config('sanctum.expiration', 43200);
            $expiresAt = now()->addMinutes($expirationMinutes > 0 ? $expirationMinutes : 43200);
            $token = $lockedUser->createToken($deviceName, $abilities, $expiresAt);

            return [
                'user' => $lockedUser->load('profile'),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt,
            ];
        });
    }
}
