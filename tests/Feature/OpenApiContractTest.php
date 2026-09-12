<?php

declare(strict_types=1);

use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

it('exports the same OpenAPI contract with or without a migrated database schema', function (): void {
    $fixture = sys_get_temp_dir().'/starter-kit-openapi-contract-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($fixture, recursive: true);

    $memoryExport = $fixture.'/openapi-memory.json';
    $migratedExport = $fixture.'/openapi-migrated.json';
    $database = $fixture.'/database.sqlite';
    $files->put($database, '');

    $environment = [
        'APP_CONFIG_CACHE' => $fixture.'/missing-config.php',
        'APP_DEBUG' => 'false',
        'APP_ENV' => 'testing',
        'APP_FALLBACK_LOCALE' => 'en',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('openapi-contract', 2)),
        'APP_LOCALE' => 'en',
        'APP_NAME' => 'Douwyn Starter Kit OpenAPI Test',
        'APP_ROUTES_CACHE' => $fixture.'/missing-routes.php',
        'APP_URL' => 'http://localhost',
        'API_DEPRECATED' => 'false',
        'API_DEPRECATION_AT' => 'null',
        'API_DEPRECATION_DOCUMENTATION_URL' => 'null',
        'API_SUNSET_AT' => 'null',
        'API_VERSION' => '1.0.0',
        'AUTH_LEGACY_TOKEN_ENDPOINTS' => 'false',
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_URL' => '',
        'LOG_CHANNEL' => 'stderr',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_COOKIE' => 'douwyn_openapi_contract_session',
        'SESSION_DRIVER' => 'array',
    ];

    $runArtisan = static function (array $arguments, array $processEnvironment): void {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            base_path(),
            $processEnvironment,
            timeout: 120,
        );
        $process->run();

        expect(
            $process->getExitCode(),
            trim($process->getErrorOutput()."\n".$process->getOutput()),
        )->toBe(0);
    };

    try {
        $runArtisan([
            'scramble:export',
            '--path='.$memoryExport,
            '--no-interaction',
        ], [
            ...$environment,
            'DB_DATABASE' => ':memory:',
        ]);

        $migratedEnvironment = [
            ...$environment,
            'DB_DATABASE' => $database,
        ];
        $runArtisan(['migrate', '--force', '--no-interaction'], $migratedEnvironment);
        $runArtisan([
            'scramble:export',
            '--path='.$migratedExport,
            '--no-interaction',
        ], $migratedEnvironment);

        $memoryDocument = json_decode(
            $files->get($memoryExport),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $migratedDocument = json_decode(
            $files->get($migratedExport),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($memoryDocument)->toBe($migratedDocument);

        $user = $memoryDocument['components']['schemas']['UserResource'];

        foreach (['email_verified_at', 'created_at', 'updated_at'] as $field) {
            expect($user['properties'][$field])->toMatchArray([
                'type' => ['string', 'null'],
                'format' => 'date-time',
            ]);
        }

        foreach (['first_name', 'last_name', 'phone', 'locale', 'timezone', 'avatar_url'] as $field) {
            expect($user['properties']['profile']['properties'][$field]['type'])
                ->toBe(['string', 'null']);
        }

        foreach (['/auth/session/login', '/auth/token/login'] as $loginPath) {
            $challengeExpiry = $memoryDocument['paths'][$loginPath]['post']['responses']['202']['content']['application/json']['schema']['properties']['data']['properties']['expires_at'];

            expect($challengeExpiry)->toMatchArray([
                'type' => 'string',
                'format' => 'date-time',
            ]);
        }
    } finally {
        $files->deleteDirectory($fixture);
    }
});

it('serializes a user without a profile using the nullable public contract', function (): void {
    $user = new User;
    $user->forceFill([
        'uuid' => '019f5092-d12a-7fc3-bb82-6c722eaeb0b3',
        'email' => 'profile-missing@example.com',
        'email_verified_at' => null,
        'created_at' => null,
        'updated_at' => null,
    ]);
    $user->setRelation('profile', null);

    $data = (new UserResource($user))->toArray(
        Request::create('/api/v1/auth/me'),
    );

    expect($data)->toMatchArray([
        'email_verified_at' => null,
        'profile' => [
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'locale' => null,
            'timezone' => null,
            'avatar_url' => null,
        ],
        'created_at' => null,
        'updated_at' => null,
    ]);
});
