<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\FlushAuthenticationState;
use Laravel\Octane\Listeners\FlushLocaleState;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Symfony\Component\Process\Process;

it('configures Swoole request and operation cleanup safeguards', function (): void {
    $listeners = config('octane.listeners');
    $requestListeners = $listeners[RequestReceived::class] ?? [];
    $operationListeners = $listeners[OperationTerminated::class] ?? [];

    expect(config('octane.server'))->toBe('swoole')
        ->and(config('octane.host'))->toBe('127.0.0.1')
        ->and(config('octane.port'))->toBe(8000)
        ->and(config('octane.task_workers'))->toBeIn([1, '1'])
        ->and(config('octane.max_requests'))->toBeGreaterThan(0)
        ->and(config('octane.garbage'))->toBeGreaterThan(0)
        ->and(config('octane.max_execution_time'))->toBeGreaterThan(0)
        ->and(config('octane.tables'))->toBeEmpty()
        ->and(config('octane.swoole.options.package_max_length'))->toBeGreaterThanOrEqual(20 * 1024 * 1024)
        ->and(config('octane.swoole.php_options'))->toContain('variables_order=EGPCS', 'opcache.enable_cli=1')
        ->and($requestListeners)->toContain(FlushAuthenticationState::class, FlushLocaleState::class)
        ->and($operationListeners)->toContain(CollectGarbage::class, FlushTemporaryContainerInstances::class)
        ->and(config('octane.watch'))->toContain('modules', 'src');
});

it('enforces Octane requirements from deployment environment settings', function (
    string $dotenv,
    array $environment,
    int $expectedExitCode,
): void {
    $fixture = sys_get_temp_dir().'/starter-kit-runtime-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($fixture.'/scripts', recursive: true);
    $files->makeDirectory($fixture.'/vendor');
    $files->copy(base_path('scripts/check-runtime.php'), $fixture.'/scripts/check-runtime.php');
    $files->put($fixture.'/.env', $dotenv);
    $files->put(
        $fixture.'/vendor/autoload.php',
        '<?php require '.var_export(base_path('vendor/autoload.php'), true).';',
    );

    try {
        $process = new Process([
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $fixture.'/scripts/check-runtime.php',
        ], $fixture, [
            'APP_ENV' => false,
            'OCTANE_SERVER' => false,
            'OCTANE_RUNTIME_REQUIRED' => false,
            ...$environment,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe($expectedExitCode)
            ->and($expectedExitCode === 0 ? $process->getOutput() : $process->getErrorOutput())
            ->toContain('Missing OPcache enabled for the CLI SAPI');
    } finally {
        $files->deleteDirectory($fixture);
    }
})->with([
    'required in dotenv' => ["OCTANE_RUNTIME_REQUIRED=true\n", [], 1],
    'production Swoole in dotenv' => ["APP_ENV=production\nOCTANE_SERVER=swoole\n", [], 1],
    'exported opt in overrides dotenv' => ["OCTANE_RUNTIME_REQUIRED=false\n", ['OCTANE_RUNTIME_REQUIRED' => 'true'], 1],
    'exported opt out overrides dotenv' => ["OCTANE_RUNTIME_REQUIRED=true\n", ['OCTANE_RUNTIME_REQUIRED' => 'false'], 0],
    'optional on a base runtime' => ["APP_ENV=local\nOCTANE_SERVER=swoole\n", [], 0],
]);
