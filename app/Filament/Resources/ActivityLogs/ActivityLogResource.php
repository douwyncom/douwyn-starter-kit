<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ManageActivityLogs;
use App\Filament\Resources\ActivityLogs\Schemas\ActivityLogForm;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

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

    public static function form(Schema $schema): Schema
    {
        return ActivityLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table)
            ->recordActions([
                ViewAction::make()->visible(fn (): bool => auth()->user()?->can('activity_logs.view') ?? false),
                DeleteAction::make()
                    ->authorize(fn (): bool => auth()->user()?->can('activity_logs.delete') ?? false)
                    ->visible(fn (): bool => auth()->user()?->can('activity_logs.delete') ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth()->user()?->can('activity_logs.delete') ?? false)
                        ->visible(fn (): bool => auth()->user()?->can('activity_logs.delete') ?? false),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActivityLogs::route('/'),
        ];
    }
}
