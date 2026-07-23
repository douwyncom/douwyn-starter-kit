<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules\Example;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class ExamplePlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'douwyn-starter-kit-example';
    }

    public function register(Panel $panel): void
    {
        // Register module resources, pages, widgets, or clusters here.
        // $panel->resources([...])->pages([...])->widgets([...]);
    }

    public function boot(Panel $panel): void
    {
        // Register runtime hooks that require the active panel here.
    }
}
