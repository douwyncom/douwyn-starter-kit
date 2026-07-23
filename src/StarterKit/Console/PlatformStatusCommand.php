<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Console;

use Douwyn\StarterKit\Modules\ModuleRegistry;
use Douwyn\StarterKit\Platform;
use Illuminate\Console\Command;

final class PlatformStatusCommand extends Command
{
    protected $signature = 'starter-kit:platform
        {--json : Output machine-readable JSON}';

    protected $description = 'Show the Douwyn Starter Kit platform contract and registered modules.';

    public function handle(ModuleRegistry $modules): int
    {
        $payload = [
            ...Platform::metadata(),
            'host_package' => Platform::hostPackage(),
            'modules' => array_map(
                static fn ($manifest): array => $manifest->toArray(),
                $modules->manifests(),
            ),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Douwyn Starter Kit platform '.Platform::VERSION);
        $this->line('Host: '.Platform::ROOT_PACKAGE);
        $this->line('Capability: '.Platform::CAPABILITY);

        if ($modules->count() === 0) {
            $this->components->info('No private modules are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Package', 'Version', 'Platform'],
            array_map(
                static fn ($manifest): array => [
                    $manifest->package,
                    $manifest->version,
                    $manifest->requiresPlatform,
                ],
                $modules->manifests(),
            ),
        );

        return self::SUCCESS;
    }
}
