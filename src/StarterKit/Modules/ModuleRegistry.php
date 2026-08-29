<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules;

use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Exceptions\IncompatibleModuleException;
use Douwyn\StarterKit\Platform;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

final class ModuleRegistry
{
    /** @var array<string, StarterKitModule> */
    private array $modules = [];

    public function register(StarterKitModule $module): void
    {
        Platform::assertHost();

        $manifest = $module->manifest();

        if (! str_starts_with($manifest->package, Platform::MODULE_PACKAGE_PREFIX)
            || $manifest->package === Platform::CAPABILITY) {
            throw new InvalidArgumentException(sprintf(
                'Module package [%s] must use the [%s*] namespace.',
                $manifest->package,
                Platform::MODULE_PACKAGE_PREFIX,
            ));
        }

        if (isset($this->modules[$manifest->package])) {
            throw new LogicException("Module [$manifest->package] is already registered.");
        }

        try {
            $supported = Platform::supports($manifest->requiresPlatform);
        } catch (UnexpectedValueException) {
            throw IncompatibleModuleException::forInvalidConstraint($manifest);
        }

        if (! $supported) {
            throw IncompatibleModuleException::forManifest($manifest);
        }

        $this->modules[$manifest->package] = $module;
        ksort($this->modules);
    }

    /** @return array<string, StarterKitModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<ModuleManifest> */
    public function manifests(): array
    {
        return array_values(array_map(
            static fn (StarterKitModule $module): ModuleManifest => $module->manifest(),
            $this->modules,
        ));
    }

    public function count(): int
    {
        return count($this->modules);
    }
}
