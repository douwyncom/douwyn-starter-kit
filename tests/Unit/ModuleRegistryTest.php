<?php

declare(strict_types=1);

use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Exceptions\IncompatibleModuleException;
use Douwyn\StarterKit\Modules\ModuleManifest;
use Douwyn\StarterKit\Modules\ModuleRegistry;

function registryTestModule(
    string $package = 'douwyncom/starter-kit-blog',
    string $version = '0.1.0',
    string $requiresPlatform = '^2.0',
): StarterKitModule {
    return new readonly class($package, $version, $requiresPlatform) implements StarterKitModule
    {
        private ModuleManifest $moduleManifest;

        public function __construct(string $package, string $version, string $requiresPlatform)
        {
            $this->moduleManifest = new ModuleManifest($package, $version, $requiresPlatform);
        }

        public function manifest(): ModuleManifest
        {
            return $this->moduleManifest;
        }
    };
}

it('registers compatible modules in deterministic package order', function () {
    $registry = new ModuleRegistry;
    $registry->register(registryTestModule('douwyncom/starter-kit-media'));
    $registry->register(registryTestModule('douwyncom/starter-kit-blog'));

    expect(array_keys($registry->all()))->toBe([
        'douwyncom/starter-kit-blog',
        'douwyncom/starter-kit-media',
    ])->and(array_map(
        static fn (ModuleManifest $manifest): array => $manifest->toArray(),
        $registry->manifests(),
    ))->toBe([
        [
            'package' => 'douwyncom/starter-kit-blog',
            'version' => '0.1.0',
            'requires_platform' => '^2.0',
        ],
        [
            'package' => 'douwyncom/starter-kit-media',
            'version' => '0.1.0',
            'requires_platform' => '^2.0',
        ],
    ]);
});

it('rejects duplicate module packages', function () {
    $registry = new ModuleRegistry;
    $registry->register(registryTestModule());

    expect(fn () => $registry->register(registryTestModule()))
        ->toThrow(LogicException::class, 'is already registered');
});

it('rejects modules for an incompatible platform', function () {
    $registry = new ModuleRegistry;

    expect(fn () => $registry->register(registryTestModule(requiresPlatform: '^3.0')))
        ->toThrow(IncompatibleModuleException::class, 'but [2.2.0] is installed');
});

it('rejects invalid platform constraints', function () {
    $registry = new ModuleRegistry;

    expect(fn () => $registry->register(registryTestModule(requiresPlatform: 'not-a-constraint !!')))
        ->toThrow(IncompatibleModuleException::class, 'invalid platform constraint');
});

it('reserves the official composer namespace for modules', function () {
    $registry = new ModuleRegistry;

    expect(fn () => $registry->register(registryTestModule(package: 'another-vendor/blog')))
        ->toThrow(InvalidArgumentException::class, 'must use the [douwyncom/starter-kit-*] namespace');
});
