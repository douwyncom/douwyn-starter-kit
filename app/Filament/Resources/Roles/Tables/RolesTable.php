<?php

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('permissions'))
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('name')
                    ->label(__('resources/role.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('guard_name')
                    ->label(__('resources/role.columns.guard'))
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label(__('resources/role.columns.permissions'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('table.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('table.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()->visible(fn () => auth()->user()?->hasRole(['super_admin']) ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->visible(fn () => auth()->user()?->hasRole(['super_admin']) ?? false),
                ]),
            ]);
    }
}
