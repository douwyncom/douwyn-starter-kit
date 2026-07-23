<?php

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Enums\TokenRevokeReason;
use App\Events\RefreshTokenReused;
use App\Http\Controllers\Api\ApiDeviceController;
use App\Http\Controllers\Api\MobileTokenController;
use App\Models\ApiDeviceSession;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    config([
        'auth_tokens.access_minutes' => 15,
        'auth_tokens.refresh_idle_days' => 30,
        'auth_tokens.refresh_absolute_days' => 90,
    ]);

    Route::post('/api/_mobile-test/token/refresh', [MobileTokenController::class, 'refresh']);
    Route::post('/api/_mobile-test/token/logout', [MobileTokenController::class, 'logout']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/api/_mobile-test/devices', [ApiDeviceController::class, 'index']);
        Route::delete('/api/_mobile-test/devices', [ApiDeviceController::class, 'destroyAll']);
        Route::delete('/api/_mobile-test/devices/{deviceSession}', [ApiDeviceController::class, 'destroy']);
    });

    $this->deviceId = (string) Str::uuid();
    $this->deviceData = new MobileDeviceData(
        deviceName: 'Test iPhone',
        deviceIdHash: app(DeviceFingerprint::class)->hash($this->deviceId),
        platform: DevicePlatform::IOS,
        appVersion: '1.0.0',
        ipAddress: '203.0.113.10',
        userAgent: 'DouwynMobile/1.0 iOS',
    );
});

it('issues a short lived access token and a hashed refresh token bound to a device family', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create();

    $pair = app(MobileTokenService::class)->issue($user, $this->deviceData);
    [$refreshTokenId, $refreshSecret] = explode('|', $pair->refreshToken, 2);
    $accessToken = PersonalAccessToken::findToken($pair->accessToken);
    $refreshToken = RefreshToken::query()->findOrFail($refreshTokenId);
    $deviceSession = $pair->deviceSession->fresh();

    expect($accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($accessToken->api_device_session_id)->toBe($deviceSession->getKey())
        ->and($accessToken->client_type->value)->toBe('mobile')
        ->and($accessToken->expires_at->equalTo(now()->addMinutes(15)))->toBeTrue()
        ->and($refreshToken->token_hash)->toBe(hash('sha256', $refreshSecret))
        ->and($refreshToken->token_hash)->not->toContain($refreshSecret)
        ->and($deviceSession->device_id_hash)->not->toBe($this->deviceId)
        ->and($deviceSession->platform)->toBe(DevicePlatform::IOS)
        ->and($deviceSession->refresh_expires_at->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($deviceSession->absolute_expires_at->equalTo(now()->addDays(90)))->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('token', $pair->accessToken)->exists())->toBeFalse()
        ->and(DB::table('refresh_tokens')->where('token_hash', $pair->refreshToken)->exists())->toBeFalse();
});

it('rejects issuance when the authenticated user snapshot changed before the user lock', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $staleAuthenticatedUser = User::query()->findOrFail($user->uuid);

    User::query()->whereKey($user->uuid)->update([
        'password' => Hash::make('Changed123'),
        'updated_at' => now(),
    ]);

    try {
        app(MobileTokenService::class)->issue($staleAuthenticatedUser, $this->deviceData);
        $this->fail('A stale authenticated snapshot must not issue credentials.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401)
            ->and($exception->getResponse()->getData(true)['code'])->toBe('authentication_state_changed');
    }

    expect(ApiDeviceSession::query()->where('user_uuid', $user->uuid)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $user->uuid)->exists())->toBeFalse();
});

it('rotates both credentials while preserving the device family', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $firstPair = $tokens->issue($user, $this->deviceData);
    [$oldRefreshId] = explode('|', $firstPair->refreshToken, 2);

    $secondPair = $tokens->rotate(
        $firstPair->refreshToken,
        $this->deviceId,
        mobileRefreshRequest(['app_version' => '1.1.0']),
    );
    [$newRefreshId] = explode('|', $secondPair->refreshToken, 2);

    expect($secondPair->deviceSession->getKey())->toBe($firstPair->deviceSession->getKey())
        ->and($secondPair->accessToken)->not->toBe($firstPair->accessToken)
        ->and($secondPair->refreshToken)->not->toBe($firstPair->refreshToken)
        ->and(PersonalAccessToken::findToken($firstPair->accessToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($secondPair->accessToken))->toBeInstanceOf(PersonalAccessToken::class)
        ->and(RefreshToken::query()->findOrFail($oldRefreshId)->used_at)->not->toBeNull()
        ->and(RefreshToken::query()->findOrFail($oldRefreshId)->replaced_by_id)->toBe($newRefreshId)
        ->and($secondPair->deviceSession->fresh()->app_version)->toBe('1.1.0')
        ->and(RefreshToken::query()->where('api_device_session_id', $firstPair->deviceSession->getKey())->count())->toBe(2)
        ->and(PersonalAccessToken::query()->where('api_device_session_id', $firstPair->deviceSession->getKey())->count())->toBe(1);
});

it('replays the same encrypted refresh result for an idempotent request id', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $firstPair = $tokens->issue($user, $this->deviceData);
    $requestId = (string) Str::uuid();

    $rotated = $tokens->rotate(
        $firstPair->refreshToken,
        $this->deviceId,
        mobileRefreshRequest(['request_id' => $requestId]),
    );
    [$sourceRefreshId] = explode('|', $firstPair->refreshToken, 2);
    $sourceRefresh = RefreshToken::query()->findOrFail($sourceRefreshId);
    $requestHash = hash('sha256', mb_strtolower($requestId));
    $cachedReplay = Cache::get('mobile-refresh-replay:'.hash(
        'sha256',
        $firstPair->refreshToken."\0".$requestHash."\0".$this->deviceData->deviceIdHash,
    ));

    Cache::flush();

    $replayed = $tokens->rotate(
        $firstPair->refreshToken,
        $this->deviceId,
        mobileRefreshRequest(['request_id' => $requestId]),
    );

    expect($replayed->accessToken)->toBe($rotated->accessToken)
        ->and($replayed->refreshToken)->toBe($rotated->refreshToken)
        ->and($replayed->deviceSession->getKey())->toBe($rotated->deviceSession->getKey())
        ->and($rotated->deviceSession->fresh()->revoked_at)->toBeNull()
        ->and(PersonalAccessToken::query()
            ->where('api_device_session_id', $rotated->deviceSession->getKey())
            ->count())->toBe(1)
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $rotated->deviceSession->getKey())
            ->count())->toBe(2)
        ->and($cachedReplay)->toBeString()
        ->and($cachedReplay)->not->toContain($rotated->accessToken)
        ->and($cachedReplay)->not->toContain($rotated->refreshToken)
        ->and($sourceRefresh->rotation_request_hash)->toBe($requestHash)
        ->and($sourceRefresh->rotation_request_hash)->not->toBe($requestId)
        ->and($sourceRefresh->rotation_device_hash)->toBe($this->deviceData->deviceIdHash)
        ->and($sourceRefresh->rotation_response)->toBeString()
        ->and($sourceRefresh->rotation_response)->not->toContain($rotated->accessToken)
        ->and($sourceRefresh->rotation_response)->not->toContain($rotated->refreshToken)
        ->and($sourceRefresh->rotation_expires_at)->not->toBeNull();
});

it('preserves the device family abilities when rotating credentials', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $firstPair = $tokens->issue($user, $this->deviceData, ['user:read']);

    $rotated = $tokens->rotate(
        $firstPair->refreshToken,
        $this->deviceId,
        mobileRefreshRequest(),
    );
    $accessToken = PersonalAccessToken::findToken($rotated->accessToken);

    expect($rotated->deviceSession->fresh()->abilities)->toBe(['user:read'])
        ->and($accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($accessToken->abilities)->toBe(['user:read'])
        ->and($accessToken->can('user:read'))->toBeTrue()
        ->and($accessToken->can('user:update'))->toBeFalse();
});

it('revokes a legacy family whose ability provenance is missing', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $pair = $tokens->issue($user, $this->deviceData, ['user:read']);
    $pair->deviceSession->forceFill(['abilities' => null])->save();

    try {
        $tokens->rotate(
            $pair->refreshToken,
            $this->deviceId,
            mobileRefreshRequest(),
        );

        $this->fail('A family with unknown ability provenance must require a new login.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::SECURITY_CHANGED)
        ->and(PersonalAccessToken::query()
            ->where('api_device_session_id', $pair->deviceSession->getKey())
            ->exists())->toBeFalse();
});

it('backfills legacy family abilities without widening privileges', function () {
    $tokens = app(MobileTokenService::class);
    $knownPair = $tokens->issue(User::factory()->create(), $this->deviceData, ['user:read']);
    $unknownPair = $tokens->issue(User::factory()->create(), $this->deviceData, ['devices:read']);

    $knownPair->deviceSession->forceFill(['abilities' => null])->save();
    $unknownPair->deviceSession->forceFill(['abilities' => null])->save();
    PersonalAccessToken::query()
        ->where('api_device_session_id', $unknownPair->deviceSession->getKey())
        ->delete();

    $migration = require database_path(
        'migrations/2026_07_11_000015_backfill_mobile_device_session_abilities.php',
    );
    $migration->up();

    expect($knownPair->deviceSession->fresh()->abilities)->toBe(['user:read'])
        ->and($knownPair->deviceSession->fresh()->revoked_at)->toBeNull()
        ->and($unknownPair->deviceSession->fresh()->abilities)->toBe([])
        ->and($unknownPair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::SECURITY_CHANGED)
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $unknownPair->deviceSession->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeFalse();
});

it('replaces an active family when the same user and device signs in again', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $firstPair = $tokens->issue($user, $this->deviceData);
    $secondPair = $tokens->issue($user, $this->deviceData);

    expect($secondPair->deviceSession->getKey())->not->toBe($firstPair->deviceSession->getKey())
        ->and($firstPair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::REPLACED_BY_NEW_LOGIN)
        ->and(PersonalAccessToken::findToken($firstPair->accessToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($secondPair->accessToken))->toBeInstanceOf(PersonalAccessToken::class)
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $firstPair->deviceSession->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeFalse()
        ->and(ApiDeviceSession::query()
            ->where('user_uuid', $user->uuid)
            ->where('device_id_hash', $this->deviceData->deviceIdHash)
            ->whereNull('revoked_at')
            ->count())->toBe(1);
});

it('commits family revocation before rejecting refresh token reuse', function () {
    Event::fake([RefreshTokenReused::class]);
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $firstPair = $tokens->issue($user, $this->deviceData);
    $secondPair = $tokens->rotate(
        $firstPair->refreshToken,
        $this->deviceId,
        mobileRefreshRequest(['request_id' => (string) Str::uuid()]),
    );

    try {
        $tokens->rotate(
            $firstPair->refreshToken,
            $this->deviceId,
            mobileRefreshRequest(['request_id' => (string) Str::uuid()]),
        );

        $this->fail('A used refresh token must not be accepted.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }

    $deviceSession = $firstPair->deviceSession->fresh();

    expect($deviceSession->revoked_at)->not->toBeNull()
        ->and($deviceSession->revoke_reason)->toBe(TokenRevokeReason::REFRESH_TOKEN_REUSED)
        ->and(PersonalAccessToken::findToken($secondPair->accessToken))->toBeNull()
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $deviceSession->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeFalse();

    Event::assertDispatched(RefreshTokenReused::class);
});

it('does not let a mismatched device identifier revoke a token family', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $pair = $tokens->issue($user, $this->deviceData);

    try {
        $tokens->rotate(
            $pair->refreshToken,
            (string) Str::uuid(),
            mobileRefreshRequest(),
        );

        $this->fail('A mismatched device identifier must not be accepted.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }

    expect($pair->deviceSession->fresh()->revoked_at)->toBeNull()
        ->and(PersonalAccessToken::findToken($pair->accessToken))->toBeInstanceOf(PersonalAccessToken::class);
});

it('revokes the family when an inactive account attempts to refresh', function () {
    $user = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $pair = $tokens->issue($user, $this->deviceData);
    $user->update(['is_inactive' => true]);

    try {
        $tokens->rotate(
            $pair->refreshToken,
            $this->deviceId,
            mobileRefreshRequest(),
        );

        $this->fail('An inactive account must not refresh credentials.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::ACCOUNT_INACTIVE)
        ->and(PersonalAccessToken::findToken($pair->accessToken))->toBeNull();
});

it('refreshes and idempotently logs out through the mobile endpoints', function () {
    $user = User::factory()->create();
    $pair = app(MobileTokenService::class)->issue($user, $this->deviceData);

    $response = $this->postJson('/api/_mobile-test/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $pair->refreshToken,
        'device_id' => $this->deviceId,
        'app_version' => '1.2.0',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.device_session_id', $pair->deviceSession->getKey())
        ->assertJsonStructure(['data' => [
            'user',
            'access_token',
            'expires_in',
            'expires_at',
            'refresh_token',
            'refresh_expires_at',
        ]]);

    $newRefreshToken = $response->json('data.refresh_token');
    $newAccessToken = $response->json('data.access_token');

    $this->postJson('/api/_mobile-test/token/logout', [
        'refresh_token' => $newRefreshToken,
        'device_id' => $this->deviceId,
    ])->assertNoContent();

    $this->postJson('/api/_mobile-test/token/logout', [
        'refresh_token' => $newRefreshToken,
        'device_id' => $this->deviceId,
    ])->assertNoContent();

    expect(PersonalAccessToken::findToken($newAccessToken))->toBeNull()
        ->and($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::LOGOUT);
});

it('lists and revokes only device sessions owned by the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $tokens = app(MobileTokenService::class);
    $pair = $tokens->issue($user, $this->deviceData);
    $otherPair = $tokens->issue($otherUser, new MobileDeviceData(
        deviceName: 'Other Android',
        deviceIdHash: app(DeviceFingerprint::class)->hash((string) Str::uuid()),
        platform: DevicePlatform::ANDROID,
        appVersion: '1.0.0',
        ipAddress: '198.51.100.2',
        userAgent: 'OtherMobile/1.0',
    ));

    $this->withToken($pair->accessToken)
        ->getJson('/api/_mobile-test/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pair->deviceSession->getKey())
        ->assertJsonMissingPath('data.0.device_id_hash');

    $this->app['auth']->forgetGuards();
    $this->withToken($pair->accessToken)
        ->deleteJson('/api/_mobile-test/devices/'.$otherPair->deviceSession->getKey())
        ->assertNotFound();

    expect($otherPair->deviceSession->fresh()->revoked_at)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($pair->accessToken)
        ->deleteJson('/api/_mobile-test/devices/'.$pair->deviceSession->getKey())
        ->assertNoContent();

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::USER_REVOKED);
});

it('cascades device families and refresh credentials when a user is deleted', function () {
    $user = User::factory()->create();
    $pair = app(MobileTokenService::class)->issue($user, $this->deviceData);
    $sessionId = $pair->deviceSession->getKey();

    $user->delete();

    expect(ApiDeviceSession::query()->whereKey($sessionId)->exists())->toBeFalse()
        ->and(RefreshToken::query()->where('api_device_session_id', $sessionId)->exists())->toBeFalse()
        ->and(PersonalAccessToken::findToken($pair->accessToken))->toBeNull();
});

function mobileRefreshRequest(array $input = []): Request
{
    return Request::create(
        '/api/v1/auth/token/refresh',
        'POST',
        array_merge(['request_id' => (string) Str::uuid()], $input),
        server: [
            'REMOTE_ADDR' => '203.0.113.20',
            'HTTP_USER_AGENT' => 'DouwynMobile/1.1 iOS',
        ],
    );
}
