<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

interface PanelAccessResolver
{
    /**
     * Decide whether an authenticated host user may enter a Filament panel.
     *
     * Modules grant the host-owned `panel.access` permission; they must not
     * require or impersonate one of the starter kit's built-in roles.
     */
    public function canAccess(Authenticatable $user, Panel $panel): bool;
}
