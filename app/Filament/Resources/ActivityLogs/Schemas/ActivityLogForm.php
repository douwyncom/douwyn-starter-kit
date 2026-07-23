<?php

namespace App\Filament\Resources\ActivityLogs\Schemas;

use App\Support\ActivityLogSanitizer;
use App\Support\Timezone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ActivityLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(1)
                ->columnSpan(2)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])->schema([
                        Section::make(__('resources/activity_log.sections.general'))
                            ->columnSpan([
                                'md' => 1,
                                'xl' => 2,
                            ])
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('log_name')
                                            ->label(__('resources/activity_log.fields.log_name')),
                                        TextInput::make('event')
                                            ->label(__('resources/activity_log.fields.event'))
                                            ->formatStateUsing(fn (?string $state): ?string => $state ? __("resources/activity_log.events.$state") : null),
                                        TextInput::make('description')
                                            ->label(__('resources/activity_log.fields.description'))
                                            ->columnSpanFull(),
                                        DateTimePicker::make('created_at')
                                            ->label(__('resources/activity_log.fields.created_at'))
                                            ->formatStateUsing(function (?string $state): ?string {
                                                if (! $state) {
                                                    return null;
                                                }

                                                return Carbon::parse($state)
                                                    ->setTimezone(Timezone::current())
                                                    ->format('Y-m-d H:i:s');
                                            })
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Section::make(__('resources/activity_log.sections.subject_causer'))
                            ->columnSpan([
                                'md' => 1,
                                'xl' => 2,
                            ])
                            ->schema([
                                TextInput::make('subject_type')
                                    ->label(__('resources/activity_log.fields.subject_type')),
                                TextInput::make('subject_id')
                                    ->label(__('resources/activity_log.fields.subject_id')),
                                TextInput::make('causer_type')
                                    ->label(__('resources/activity_log.fields.causer_type')),
                                TextInput::make('causer_id')
                                    ->label(__('resources/activity_log.fields.causer_id')),
                            ]),

                        Section::make(__('resources/activity_log.sections.properties'))
                            ->columnSpanFull()
                            ->schema([
                                KeyValue::make('properties')
                                    ->label(__('resources/activity_log.fields.properties'))
                                    ->keyLabel(__('resources/activity_log.fields.field'))
                                    ->valueLabel(__('resources/activity_log.fields.value'))
                                    ->formatStateUsing(fn (mixed $state): array => ActivityLogSanitizer::sanitize($state)),
                            ]),
                    ]),
                ]),
        ]);
    }
}
