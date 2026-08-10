<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Auth;

use Douwyn\StarterKit\Contracts\PanelAccessResolver;
use Douwyn\StarterKit\Platform;
use Filament\Panel;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class PermissionPanelAccessResolver implements PanelAccessResolver
{
    public function canAccess(Authenticatable $user, Panel $panel): bool
    {
        if ($panel->getId() !== Platform::ADMIN_PANEL_ID
            || ! $user instanceof Authorizable
            || $this->isInactive($user)) {
            return false;
        }

        return $user->can('panel.access');
    }

    private function isInactive(Authenticatable $user): bool
    {
        if (! method_exists($user, 'getAttribute')) {
            return false;
        }

        return (bool) $user->getAttribute('is_inactive');
    }
}
