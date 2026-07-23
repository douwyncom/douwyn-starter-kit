<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules\Example;

use Douwyn\StarterKit\Contracts\StarterKitModule;
use Douwyn\StarterKit\Modules\ModuleServiceProvider;

final class ExampleServiceProvider extends ModuleServiceProvider
{
    protected function module(): StarterKitModule
    {
        return new ExampleModule;
    }

    protected function registerModule(): void
    {
        // Merge package configuration and register module bindings here.
    }

    public function boot(): void
    {
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // $this->loadTranslationsFrom(__DIR__.'/../lang', 'starter-kit-example');
    }
}
