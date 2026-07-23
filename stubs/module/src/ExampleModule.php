<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules\Example;

use Composer\InstalledVersions;
use Douwyn\StarterKit\Contracts\ProvidesFilamentPlugin;
use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Modules\ModuleManifest;
use Filament\Contracts\Plugin;

final class ExampleModule implements ProvidesFilamentPlugin, StarterKitModule
{
    private ?Plugin $plugin = null;

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            package: 'douwyncom/starter-kit-example',
            version: InstalledVersions::getPrettyVersion('douwyncom/starter-kit-example') ?? 'dev-main',
            requiresPlatform: '^2.0',
        );
    }

    public function filamentPlugin(): Plugin
    {
        return $this->plugin ??= ExamplePlugin::make();
    }
}
