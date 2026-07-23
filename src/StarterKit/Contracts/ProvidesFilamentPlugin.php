<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Filament\Contracts\Plugin;

interface ProvidesFilamentPlugin
{
    public function filamentPlugin(): Plugin;
}
