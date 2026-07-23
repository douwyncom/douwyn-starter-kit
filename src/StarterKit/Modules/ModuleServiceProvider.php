<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules;

use Douwyn\StarterKit\Contracts\ProvidesFilamentPlugin;
use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Platform;
use Filament\Panel;
use Illuminate\Support\ServiceProvider;
use LogicException;

abstract class ModuleServiceProvider extends ServiceProvider
{
    final public function register(): void
    {
        $module = $this->module();

        if ($this->app->resolved(ModuleRegistry::class)) {
            $this->app->make(ModuleRegistry::class)->register($module);
        } else {
            $this->app->afterResolving(
                ModuleRegistry::class,
                static function (ModuleRegistry $registry) use ($module): void {
                    $registry->register($module);
                },
            );
        }

        if ($module instanceof ProvidesFilamentPlugin) {
            $plugin = $module->filamentPlugin();
            $manifest = $module->manifest();

            Panel::configureUsing(static function (Panel $panel) use ($manifest, $plugin): void {
                if ($panel->getId() !== Platform::ADMIN_PANEL_ID) {
                    return;
                }

                if ($panel->hasPlugin($plugin->getId())) {
                    throw new LogicException(sprintf(
                        'Filament plugin [%s] from module [%s] is already registered.',
                        $plugin->getId(),
                        $manifest->package,
                    ));
                }

                $panel->plugin($plugin);
            });
        }

        $this->registerModule();
    }

    abstract protected function module(): StarterKitModule;

    /**
     * Register package-specific bindings or merge package configuration here.
     */
    protected function registerModule(): void {}
}
