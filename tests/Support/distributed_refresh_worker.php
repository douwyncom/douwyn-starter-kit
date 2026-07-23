<?php

declare(strict_types=1);

use App\Services\Auth\MobileTokenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $jobId, $requestId] = $argv + [null, null, null];

if (! is_string($jobId) || ! is_string($requestId)) {
    fwrite(STDERR, "Missing distributed refresh worker arguments.\n");
    exit(2);
}

$payloadKey = "distributed-auth:payload:{$jobId}";
$readyKey = "distributed-auth:ready:{$jobId}";
$encryptedPayload = Cache::get($payloadKey);

if (! is_string($encryptedPayload)) {
    fwrite(STDERR, "Distributed refresh payload is unavailable.\n");
    exit(3);
}

$payload = json_decode(Crypt::decryptString($encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
Cache::increment($readyKey);

$deadline = microtime(true) + 10;

while ((int) Cache::get($readyKey, 0) < 2 && microtime(true) < $deadline) {
    usleep(20_000);
}

if ((int) Cache::get($readyKey, 0) < 2) {
    fwrite(STDERR, "Distributed refresh barrier timed out.\n");
    exit(4);
}

$request = Request::create(
    '/api/v1/auth/token/refresh',
    'POST',
    [
        'request_id' => $requestId,
        'app_version' => 'distributed-test',
    ],
    server: [
        'REMOTE_ADDR' => '203.0.113.50',
        'HTTP_USER_AGENT' => 'DouwynDistributedAuthTest/1.0',
    ],
);

try {
    $pair = app(MobileTokenService::class)->rotate(
        (string) $payload['refresh_token'],
        (string) $payload['device_id'],
        $request,
    );

    $result = [
        'status' => 200,
        'access_hash' => hash('sha256', $pair->accessToken),
        'refresh_hash' => hash('sha256', $pair->refreshToken),
        'device_session_id' => (string) $pair->deviceSession->getKey(),
    ];
} catch (HttpResponseException $exception) {
    $result = [
        'status' => $exception->getResponse()->getStatusCode(),
        'code' => $exception->getResponse()->getData(true)['code'] ?? null,
    ];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
