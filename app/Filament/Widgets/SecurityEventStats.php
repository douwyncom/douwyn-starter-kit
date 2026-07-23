<?php

namespace App\Filament\Widgets;

use App\Enums\SecurityEvent;
use App\Services\Security\SecurityTelemetry;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class SecurityEventStats extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->can('activity_logs.view') ?? false;
    }

    protected function getHeading(): ?string
    {
        return __('dashboard.security_events.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.security_events.description');
    }

    protected function getStats(): array
    {
        $counts = Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('created_at', '>=', now()->subDays(7))
            ->select(['event', DB::raw('count(*) as aggregate')])
            ->groupBy('event')
            ->pluck('aggregate', 'event');

        $count = fn (SecurityEvent ...$events): int => collect($events)
            ->sum(fn (SecurityEvent $event): int => (int) $counts->get($event->value, 0));

        $failedLogins = $count(SecurityEvent::LOGIN_FAILED);
        $failedTwoFactor = $count(SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED);
        $refreshReuse = $count(SecurityEvent::REFRESH_TOKEN_REUSED);
        $revocations = Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('event', [
                SecurityEvent::BROWSER_SESSION_REVOKED->value,
                SecurityEvent::DEVICE_SESSION_REVOKED->value,
            ])
            ->get(['properties'])
            ->sum(fn (Activity $activity): int => max(
                1,
                (int) $activity->getExtraProperty('revoked_count', 1),
            ));

        return [
            Stat::make(__('dashboard.security_events.failed_logins'), $failedLogins)
                ->description(__('dashboard.security_events.last_seven_days'))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($failedLogins > 0 ? 'warning' : 'success'),

            Stat::make(__('dashboard.security_events.failed_two_factor'), $failedTwoFactor)
                ->description(__('dashboard.security_events.last_seven_days'))
                ->descriptionIcon(Heroicon::OutlinedShieldExclamation)
                ->color($failedTwoFactor > 0 ? 'danger' : 'success'),

            Stat::make(__('dashboard.security_events.refresh_reuse'), $refreshReuse)
                ->description(__('dashboard.security_events.last_seven_days'))
                ->descriptionIcon(Heroicon::OutlinedKey)
                ->color($refreshReuse > 0 ? 'danger' : 'success'),

            Stat::make(__('dashboard.security_events.revocations'), $revocations)
                ->description(__('dashboard.security_events.last_seven_days'))
                ->descriptionIcon(Heroicon::OutlinedNoSymbol)
                ->color('info'),
        ];
    }
}
