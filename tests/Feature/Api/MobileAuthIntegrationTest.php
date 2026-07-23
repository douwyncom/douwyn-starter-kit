<?php

use App\Enums\TokenRevokeReason;
use App\Enums\TwoFactorMethod;
use App\Models\ApiDeviceSession;
use App\Models\AuthChallenge;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'auth_tokens.access_minutes' => 15,
        'auth_tokens.refresh_idle_days' => 30,
        'auth_tokens.refresh_absolute_days' => 90,
    ]);
});

it('requires device binding metadata on canonical mobile endpoints', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Unbound client',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id', 'platform']);

    expect($user->tokens()->count())->toBe(0)
        ->and($user->apiDeviceSessions()->count())->toBe(0);
});

it('rate limits refresh attempts by ip even when caller mutates all identifiers', function () {
    foreach (range(1, 30) as $attempt) {
        $this->postJson('/api/v1/auth/token/refresh', [
            'request_id' => (string) Str::uuid(),
            'refresh_token' => (string) Str::uuid().'|douwyn_rt_'.Str::random(64),
            'device_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => (string) Str::uuid().'|douwyn_rt_'.Str::random(64),
        'device_id' => (string) Str::uuid(),
    ])->assertTooManyRequests();
});

it('registers a mobile account with a rotating token pair', function () {
    $deviceId = (string) Str::uuid();

    $response = $this->postJson('/api/v1/auth/token/register', [
        'email' => 'mobile@example.com',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'first_name' => 'Mobile',
        'last_name' => 'User',
        'device_name' => 'iPhone 17',
        'device_id' => $deviceId,
        'platform' => 'ios',
        'app_version' => '1.0.0',
    ])->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => [
            'user',
            'access_token',
            'expires_in',
            'expires_at',
            'refresh_token',
            'refresh_expires_at',
            'device_session_id',
        ]]);

    $session = ApiDeviceSession::query()->sole();

    expect($session->device_id_hash)->not->toBe($deviceId)
        ->and(PersonalAccessToken::findToken($response->json('data.access_token')))
        ->toBeInstanceOf(PersonalAccessToken::class);
});

it('seals mobile device metadata into a two factor challenge', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $deviceId = (string) Str::uuid();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $challengeToken = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Pixel 11',
        'device_id' => $deviceId,
        'platform' => 'android',
        'app_version' => '2.4.0',
    ])->assertStatus(202)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('code', 'two_factor_required')
        ->json('data.challenge_token');

    $challenge = AuthChallenge::query()->sole();

    expect($challenge->device_id_hash)->not->toBeNull()
        ->and($challenge->device_id_hash)->not->toBe($deviceId)
        ->and($challenge->platform)->toBe('android')
        ->and($challenge->app_version)->toBe('2.4.0');

    $response = $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $challengeToken,
        'device_id' => $deviceId,
        'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
    ])->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'device_session_id']]);

    expect(PersonalAccessToken::findToken($response->json('data.access_token')))
        ->toBeInstanceOf(PersonalAccessToken::class)
        ->and(ApiDeviceSession::query()->sole()->platform->value)->toBe('android');
});

it('rejects a stolen mobile challenge from a different installation', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $deviceId = (string) Str::uuid();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $challengeToken = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Bound iPhone',
        'device_id' => $deviceId,
        'platform' => 'ios',
    ])->assertStatus(202)->json('data.challenge_token');

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $challengeToken,
        'device_id' => (string) Str::uuid(),
        'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
    ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');

    expect(AuthChallenge::query()->sole()->consumed_at)->not->toBeNull()
        ->and($user->apiDeviceSessions()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);
});

it('rotates and logs out a canonical mobile session through public refresh endpoints', function () {
    $deviceId = (string) Str::uuid();
    $user = User::factory()->create(['password' => 'Secret123']);

    $login = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'iPhone',
        'device_id' => $deviceId,
        'platform' => 'ios',
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $refresh = $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $login->json('data.refresh_token'),
        'device_id' => $deviceId,
        'app_version' => '1.1.0',
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache');

    $accessToken = $refresh->json('data.access_token');
    $refreshToken = $refresh->json('data.refresh_token');

    $this->withToken($accessToken)
        ->getJson('/api/v1/auth/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/auth/token/logout', [
        'refresh_token' => $refreshToken,
        'device_id' => $deviceId,
    ])->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($accessToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('keeps the current mobile family and revokes other devices after a password change', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $firstDeviceId = (string) Str::uuid();
    $secondDeviceId = (string) Str::uuid();

    $first = mobileLogin($this, $user, $firstDeviceId, 'Current iPhone');
    $second = mobileLogin($this, $user, $secondDeviceId, 'Other iPhone');

    $this->withToken($first['access_token'])
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Secret123',
            'password' => 'Changed123',
            'password_confirmation' => 'Changed123',
        ])->assertOk();

    $currentSession = ApiDeviceSession::query()->findOrFail($first['device_session_id']);
    $otherSession = ApiDeviceSession::query()->findOrFail($second['device_session_id']);

    expect($currentSession->revoked_at)->toBeNull()
        ->and($otherSession->revoked_at)->not->toBeNull()
        ->and($otherSession->revoke_reason)->toBe(TokenRevokeReason::PASSWORD_CHANGED)
        ->and(PersonalAccessToken::findToken($first['access_token']))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($second['access_token']))->toBeNull();
});

function mobileLogin($test, User $user, string $deviceId, string $deviceName): array
{
    return $test->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => $deviceName,
        'device_id' => $deviceId,
        'platform' => 'ios',
    ])->assertOk()->json('data');
}
