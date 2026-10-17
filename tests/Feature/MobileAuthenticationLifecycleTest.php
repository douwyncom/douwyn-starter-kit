<?php

declare(strict_types=1);

use App\Models\ApiDeviceSession;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function mobileReleaseLoginPayload(User $user): array
{
    return [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Release test phone',
        'device_id' => (string) Str::uuid(),
        'platform' => 'ios',
    ];
}

it('rotates mobile credentials idempotently and revokes the family on refresh reuse', function (): void {
    $user = User::factory()->create();
    $device = mobileReleaseLoginPayload($user);
    $pair = $this->postJson('/api/v1/auth/token/login', $device)
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->json('data');

    expect(RefreshToken::query()->sole()->token_hash)->not->toBe($pair['refresh_token']);

    $rotation = [
        'request_id' => (string) Str::uuid(),
        'device_id' => $device['device_id'],
        'refresh_token' => $pair['refresh_token'],
    ];
    $next = $this->postJson('/api/v1/auth/token/refresh', $rotation)->assertOk()->json('data');
    expect($next['access_token'])->not->toBe($pair['access_token'])
        ->and($next['refresh_token'])->not->toBe($pair['refresh_token']);

    $this->postJson('/api/v1/auth/token/refresh', $rotation)
        ->assertOk()
        ->assertJsonPath('data.access_token', $next['access_token'])
        ->assertJsonPath('data.refresh_token', $next['refresh_token']);

    $this->postJson('/api/v1/auth/token/refresh', [
        ...$rotation,
        'request_id' => (string) Str::uuid(),
    ])->assertUnauthorized()->assertJsonPath('code', 'reauthentication_required');

    expect(ApiDeviceSession::query()->sole()->revoked_at)->not->toBeNull();
    $this->postJson('/api/v1/auth/token/refresh', [
        ...$rotation,
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $next['refresh_token'],
    ])->assertUnauthorized();
});

it('rejects invalid credentials and inactive users without creating device sessions', function (): void {
    $user = User::factory()->create();
    $payload = mobileReleaseLoginPayload($user);
    $this->postJson('/api/v1/auth/token/login', [...$payload, 'password' => 'incorrect'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $user->forceFill(['is_inactive' => true])->save();
    $this->postJson('/api/v1/auth/token/login', $payload)
        ->assertForbidden()->assertJsonPath('code', 'account_inactive');

    expect(ApiDeviceSession::query()->count())->toBe(0)
        ->and(RefreshToken::query()->count())->toBe(0);
});

it('revokes refresh credentials on mobile logout', function (): void {
    $payload = mobileReleaseLoginPayload(User::factory()->create());
    $pair = $this->postJson('/api/v1/auth/token/login', $payload)->assertOk()->json('data');
    $this->postJson('/api/v1/auth/token/logout', [
        'device_id' => $payload['device_id'],
        'refresh_token' => $pair['refresh_token'],
    ])->assertNoContent();

    $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'device_id' => $payload['device_id'],
        'refresh_token' => $pair['refresh_token'],
    ])->assertUnauthorized();
});
