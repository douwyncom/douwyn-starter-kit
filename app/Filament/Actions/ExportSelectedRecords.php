<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportSelectedRecords
{
    public static function make(): BulkAction
    {
        return BulkAction::make('exportSelected')
            ->label(__('table.bulk.export'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize(fn (Table $table): bool => self::canExport($table))
            ->visible(fn (Table $table): bool => self::canExport($table))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Table $table, Collection $records): StreamedResponse => self::download($table, $records));
    }

    public static function canExport(Table $table): bool
    {
        $user = auth()->user();

        if (! $user || $user->is_inactive || ! $user->can('panel.access') || ! $user->can('tables.export') || ! $table->hasQuery()) {
            return false;
        }

        $livewire = $table->getLivewire();

        if ($livewire instanceof ListRecords) {
            $resource = $livewire::getResource();

            return $resource::canAccess() && $resource::canViewAny();
        }

        if ($livewire instanceof RelationManager) {
            $owner = $livewire->getOwnerRecord();

            return $livewire::canViewForRecord($owner, $livewire->getPageClass())
                && (auth()->user()?->can('view', $owner) ?? false);
        }

        if ($livewire instanceof TableWidget) {
            // Only opt in widgets that explicitly restrict their own visibility.
            return (new ReflectionMethod($livewire, 'canView'))->getDeclaringClass()->getName() !== Widget::class
                && $livewire::canView();
        }

        $model = $table->getQuery()->getModel();

        // Custom widgets / relationship tables must have a real read policy.
        return Gate::getPolicyFor($model) !== null && $user->can('viewAny', $model::class);
    }

    public static function canRead(Table $table, Model $record): bool
    {
        $livewire = $table->getLivewire();

        if ($livewire instanceof ListRecords) {
            return $livewire::getResource()::canView($record);
        }

        if ($livewire instanceof RelationManager || $livewire instanceof TableWidget) {
            return self::canExport($table)
                && (Gate::getPolicyFor($record) === null || (auth()->user()?->can('view', $record) ?? false));
        }

        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function download(Table $table, Collection $records): StreamedResponse
    {
        abort_unless(self::canExport($table), 403);

        if ($records->isEmpty() || $records->count() > 1000) {
            throw ValidationException::withMessages(['records' => __('table.bulk.selection_limit')]);
        }

        foreach ($records as $record) {
            abort_unless($record instanceof Model && self::canRead($table, $record), 403);
        }

        // Do not trust IDs supplied by the browser or a stale mounted action.
        $keys = $records->map(fn (Model $record): mixed => $record->getKey())->all();
        abort_unless($table->getLivewire()->getFilteredTableQuery()->whereKey($keys)->count() === $records->count(), 403);

        $columns = collect($table->getColumns())->filter(static function ($original) use ($records): bool {
            $column = clone $original;
            $column->record($records->first());

            return ($column instanceof TextColumn || $column instanceof IconColumn)
                && $column->isVisible() && ! $column->isToggledHidden()
                && ! preg_match('/password|secret|token|credential|recovery|payload|private_key/i', $column->getName());
        });
        $rows = [];

        foreach ($records as $record) {
            $rows[] = $columns->map(static function ($original) use ($record): string {
                $column = clone $original;
                $column->record($record);
                $column->clearCachedState();

                if (! $column->isVisible() || $column->isToggledHidden()) {
                    return '';
                }

                $state = $column->getState();

                if ($column instanceof TextColumn) {
                    if (blank($state)) {
                        return self::cell($column->getPlaceholder());
                    }

                    $state = is_array($state)
                        ? array_map($column->formatState(...), $state)
                        : $column->formatState($state);
                }

                return self::cell($state);
            })->values()->all();
        }

        $headers = $columns->map(fn ($column): string => self::cell($column->getLabel()))->values()->all();

        return response()->streamDownload(static function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");

            foreach ([$headers, ...$rows] as $row) {
                fputcsv($stream, $row, escape: '');
            }

            fclose($stream);
        }, 'selected-records-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function cell(mixed $value): string
    {
        $value = match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof Htmlable => $value->toHtml(),
            is_bool($value) => $value ? __('table.bulk.yes') : __('table.bulk.no'),
            is_array($value) => implode('; ', array_map(self::cell(...), $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
        $value = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // Prevent spreadsheet formula injection, including whitespace prefixes.
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $value) ? "'$value" : $value;
    }
}
