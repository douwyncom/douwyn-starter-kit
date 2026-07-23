<?php

namespace App\Filament\Clusters\Settings;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class SettingsCluster extends Cluster
{
    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationLabel(): string
    {
        return __('pages/settings.cluster');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pages/settings.cluster');
    }

    // Render sub-navigation as a sidebar:
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;
}
