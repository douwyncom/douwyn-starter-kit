<?php

namespace App\Filament\Clusters\Account;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AccountCluster extends Cluster
{
    protected static ?string $slug = 'account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Account';

    public static function getNavigationLabel(): string
    {
        return __('pages/account.cluster');
    }

    protected static string|null|UnitEnum $navigationGroup = 'User';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static bool $shouldRegisterNavigation = false;
}
