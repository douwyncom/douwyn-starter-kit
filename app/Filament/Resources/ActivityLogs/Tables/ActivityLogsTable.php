<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Enums\SecurityEvent;
use App\Support\Timezone;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->label(__('resources/activity_log.fields.log_name'))
                    ->badge()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event')
                    ->label(__('resources/activity_log.fields.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        SecurityEvent::LOGIN_FAILED->value,
                        SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED->value => 'warning',
                        SecurityEvent::REFRESH_TOKEN_REUSED->value => 'danger',
                        SecurityEvent::LOGIN_SUCCEEDED->value,
                        SecurityEvent::TWO_FACTOR_VERIFIED->value => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): ?string => $state ? __("resources/activity_log.events.$state") : null),
                TextColumn::make('description')
                    ->label(__('resources/activity_log.fields.description'))
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label(__('resources/activity_log.fields.subject_type'))
                    ->formatStateUsing(fn (?string $state): ?string => $state ? str_replace('App\\Models\\', '', $state) : null)
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),
                TextColumn::make('causer.profile.first_name')
                    ->label(__('resources/activity_log.fields.causer'))
                    ->placeholder('System'),
                TextColumn::make('created_at')
                    ->label(__('resources/activity_log.fields.created_at'))
                    ->dateTime(format: 'Y-m-d H:i:s', timezone: fn () => Timezone::current())
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('resources/activity_log.fields.event'))
                    ->options([
                        'created' => __('resources/activity_log.events.created'),
                        'updated' => __('resources/activity_log.events.updated'),
                        'deleted' => __('resources/activity_log.events.deleted'),
                        'restored' => __('resources/activity_log.events.restored'),
                        ...SecurityEvent::options(),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
