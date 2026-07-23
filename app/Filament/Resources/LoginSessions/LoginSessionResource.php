<?php

namespace App\Filament\Resources\LoginSessions;

use App\Filament\Resources\LoginSessions\Pages\ManageLoginSessions;
use App\Filament\Resources\LoginSessions\Tables\LoginSessionsTable;
use App\Models\LoginSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LoginSessionResource extends Resource
{
    protected static ?string $model = LoginSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('resources/login_session.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('resources/user.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('resources/login_session.resource_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources/login_session.plural_resource_label');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('login_sessions.view') ?? false;
    }

    public static function table(Table $table): Table
    {
        return LoginSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLoginSessions::route('/'),
        ];
    }
}
