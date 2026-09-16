<?php

namespace App\Filament\Resources\ActivityLogs\Schemas;

use App\Support\ActivityLogSanitizer;
use App\Support\Timezone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
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
                ->columnSpanFull()
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
                                        TextInput::make('id')
                                            ->label(__('resources/activity_log.fields.id'))
                                            ->formatStateUsing(fn (int|string|null $state): ?string => $state === null ? null : (string) $state)
                                            ->readOnly()
                                            ->dehydrated(false),
                                        TextInput::make('log_name')
                                            ->label(__('resources/activity_log.fields.log_name'))
                                            ->formatStateUsing(self::localizeLogName(...)),
                                        TextInput::make('event')
                                            ->label(__('resources/activity_log.fields.event'))
                                            ->formatStateUsing(fn (?string $state): ?string => $state ? __("resources/activity_log.events.$state") : null),
                                        TextInput::make('description')
                                            ->label(__('resources/activity_log.fields.description'))
                                            ->formatStateUsing(self::localizeDescription(...))
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
                                    ->label(__('resources/activity_log.fields.subject_id'))
                                    ->readOnly()
                                    ->dehydrated(false),
                                TextInput::make('causer_type')
                                    ->label(__('resources/activity_log.fields.causer_type')),
                                TextInput::make('causer_id')
                                    ->label(__('resources/activity_log.fields.causer_id'))
                                    ->readOnly()
                                    ->dehydrated(false),
                            ]),

                        Section::make(__('resources/activity_log.sections.properties'))
                            ->columnSpanFull()
                            ->schema([
                                Textarea::make('properties')
                                    ->label(__('resources/activity_log.fields.properties'))
                                    ->rows(12)
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (mixed $state): string => json_encode(
                                        ActivityLogSanitizer::sanitize($state),
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                                    )),
                            ]),
                    ]),
                ]),
        ]);
    }

    private static function localizeLogName(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        $key = "resources/activity_log.log_names.$value";
        $translated = __($key);

        return $translated === $key ? $value : $translated;
    }

    private static function localizeDescription(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        $event = str_starts_with($value, 'security.')
            ? substr($value, strlen('security.'))
            : $value;
        $key = "resources/activity_log.events.$event";
        $translated = __($key);

        return $translated === $key ? $value : $translated;
    }
}
