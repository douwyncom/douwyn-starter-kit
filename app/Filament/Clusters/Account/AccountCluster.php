<?php

namespace App\Filament\Clusters\Account;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class AccountCluster extends Cluster
{
    protected static ?string $slug = 'account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function getNavigationLabel(): string
    {
        return __('pages/account.cluster');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('pages/account.cluster');
    }

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static bool $shouldRegisterNavigation = false;
}
