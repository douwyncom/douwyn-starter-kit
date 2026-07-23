<?php

namespace App\Filament\Widgets;

use App\Enums\SecurityEvent;
use App\Services\Security\SecurityTelemetry;
use App\Support\Timezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Spatie\Activitylog\Models\Activity;

class RecentSecurityEvents extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('activity_logs.view') ?? false;
    }

    public function getTableHeading(): string
    {
        return __('dashboard.security_events.recent_heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()
                ->where('log_name', SecurityTelemetry::LOG_NAME)
                ->where('created_at', '>=', now()->subDays(7))
                ->with(['subject', 'causer']))
            ->columns([
                TextColumn::make('event')
                    ->label(__('dashboard.security_events.event'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        SecurityEvent::LOGIN_FAILED->value,
                        SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED->value => 'warning',
                        SecurityEvent::REFRESH_TOKEN_REUSED->value => 'danger',
                        SecurityEvent::LOGIN_SUCCEEDED->value,
                        SecurityEvent::TWO_FACTOR_VERIFIED->value => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __("resources/activity_log.events.{$state}")
                        : '—'),
                TextColumn::make('subject.email')
                    ->label(__('dashboard.security_events.user'))
                    ->placeholder(__('dashboard.security_events.anonymous'))
                    ->searchable(),
                TextColumn::make('context')
                    ->label(__('dashboard.security_events.context'))
                    ->state(fn (Activity $record): string => collect([
                        $record->getExtraProperty('channel'),
                        $record->getExtraProperty('reason') ?? $record->getExtraProperty('change'),
                    ])->filter()->map(fn (string $value): string => str_replace('_', ' ', $value))->implode(' · ') ?: '—'),
                TextColumn::make('ip_address')
                    ->label(__('dashboard.security_events.ip_address'))
                    ->state(fn (Activity $record): ?string => $record->getExtraProperty('ip_address'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('dashboard.security_events.recorded_at'))
                    ->dateTime(format: 'Y-m-d H:i:s', timezone: fn () => Timezone::current())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('dashboard.security_events.event'))
                    ->options(SecurityEvent::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
