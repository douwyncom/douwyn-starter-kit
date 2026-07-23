<?php

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Enums\TokenRevokeReason;
use App\Models\ApiDeviceSession;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (config('database.default') !== 'pgsql' || config('cache.default') !== 'redis') {
        $this->markTestSkipped('Distributed auth tests require PostgreSQL and Redis.');
    }

    Cache::flush();
});

afterEach(function () {
    Cache::flush();
});

it('returns one identical token pair for concurrent retries with the same request id', function () {
    $context = createDistributedMobileFamily();
    $requestId = (string) Str::uuid();

    try {
        [$first, $second] = runConcurrentRefreshWorkers($context, $requestId, $requestId);

        expect($first['status'])->toBe(200)
            ->and($second['status'])->toBe(200)
            ->and($first['access_hash'])->toBe($second['access_hash'])
            ->and($first['refresh_hash'])->toBe($second['refresh_hash'])
            ->and($context['session']->fresh()->revoked_at)->toBeNull()
            ->and(PersonalAccessToken::query()
                ->where('api_device_session_id', $context['session']->getKey())
                ->count())->toBe(1)
            ->and(RefreshToken::query()
                ->where('api_device_session_id', $context['session']->getKey())
                ->count())->toBe(2);
    } finally {
        $context['user']->delete();
    }
});

it('revokes the family when concurrent callers reuse a token with different request ids', function () {
    $context = createDistributedMobileFamily();

    try {
        $results = runConcurrentRefreshWorkers(
            $context,
            (string) Str::uuid(),
            (string) Str::uuid(),
        );
        $statuses = collect($results)->pluck('status')->sort()->values()->all();

        expect($statuses)->toBe([200, 401])
            ->and($context['session']->fresh()->revoke_reason)
            ->toBe(TokenRevokeReason::REFRESH_TOKEN_REUSED)
            ->and(PersonalAccessToken::query()
                ->where('api_device_session_id', $context['session']->getKey())
                ->exists())->toBeFalse()
            ->and(RefreshToken::query()
                ->where('api_device_session_id', $context['session']->getKey())
                ->whereNull('revoked_at')
                ->exists())->toBeFalse();
    } finally {
        $context['user']->delete();
    }
});

/** @return array{user: User, session: ApiDeviceSession, refresh_token: string, device_id: string} */
function createDistributedMobileFamily(): array
{
    $user = User::factory()->create();
    $deviceId = (string) Str::uuid();
    $pair = app(MobileTokenService::class)->issue(
        $user,
        new MobileDeviceData(
            deviceName: 'Distributed iPhone',
            deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
            platform: DevicePlatform::IOS,
            appVersion: '1.0.0',
            ipAddress: '203.0.113.40',
            userAgent: 'DouwynDistributedAuthTest/1.0',
        ),
        ['user:read'],
    );

    return [
        'user' => $user,
        'session' => $pair->deviceSession,
        'refresh_token' => $pair->refreshToken,
        'device_id' => $deviceId,
    ];
}

/**
 * @param  array{refresh_token: string, device_id: string}  $context
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function runConcurrentRefreshWorkers(array $context, string $firstRequestId, string $secondRequestId): array
{
    $jobId = (string) Str::uuid();
    $payloadKey = "distributed-auth:payload:{$jobId}";
    $readyKey = "distributed-auth:ready:{$jobId}";
    Cache::put($payloadKey, Crypt::encryptString(json_encode([
        'refresh_token' => $context['refresh_token'],
        'device_id' => $context['device_id'],
    ], JSON_THROW_ON_ERROR)), now()->addMinute());
    Cache::put($readyKey, 0, now()->addMinute());

    $worker = base_path('tests/Support/distributed_refresh_worker.php');
    $processes = [
        new Process([PHP_BINARY, $worker, $jobId, $firstRequestId], base_path()),
        new Process([PHP_BINARY, $worker, $jobId, $secondRequestId], base_path()),
    ];

    try {
        foreach ($processes as $process) {
            $process->setTimeout(20)->start();
        }

        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())
                ->toBeTrue($process->getErrorOutput().$process->getOutput());
        }

        return array_map(
            fn (Process $process): array => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            $processes,
        );
    } finally {
        Cache::forget($payloadKey);
        Cache::forget($readyKey);
    }
}
