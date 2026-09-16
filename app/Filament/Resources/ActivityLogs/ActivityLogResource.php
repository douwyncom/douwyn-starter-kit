<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ManageActivityLogs;
use App\Filament\Resources\ActivityLogs\Schemas\ActivityLogForm;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('resources/activity_log.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('resources/activity_log.navigation_group');
    }

    public static function getLabel(): ?string
    {
        return __('resources/activity_log.resource_label');
    }

    public static function getPluralLabel(): ?string
    {
        return __('resources/activity_log.plural_resource_label');
    }

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('activity_logs.view') ?? false;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return in_array($action, ['viewAny', 'view'], true) && static::canAccess()
            ? Response::allow()
            : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return ActivityLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table)
            ->recordActions([
                ViewAction::make()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->authorize(fn (Activity $record): bool => static::canView($record)),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivityLogs::route('/'),
        ];
    }
}
