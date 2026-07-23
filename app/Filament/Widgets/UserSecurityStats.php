<?php

namespace App\Filament\Widgets;

use App\Enums\TokenRevokeReason;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApiDeviceSession;
use App\Models\LoginSession;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UserSecurityStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->can('users.view') ?? false;
    }

    protected function getHeading(): ?string
    {
        return __('dashboard.user_security.heading');
    }

    protected function getDescription(): ?string
    {
        return __('dashboard.user_security.description');
    }

    protected function getStats(): array
    {
        $totalUsers = User::query()->count();
        $activeUsers = User::query()->where('is_inactive', false)->count();
        $twoFactorUsers = User::query()->whereNotNull('two_factor_enabled_at')->count();
        $activeSessions = LoginSession::query()->active()->count();
        $activeApiTokens = PersonalAccessToken::query()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
        $activeMobileDevices = ApiDeviceSession::query()->active()->count();
        $compromisedMobileDevices = ApiDeviceSession::query()
            ->where('revoke_reason', TokenRevokeReason::REFRESH_TOKEN_REUSED->value)
            ->where('revoked_at', '>=', now()->subDays(7))
            ->count();

        return [
            Stat::make(__('dashboard.user_security.total_users'), $totalUsers)
                ->description(__('dashboard.user_security.active_users', ['count' => $activeUsers]))
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary')
                ->url(UserResource::getUrl()),

            Stat::make(__('dashboard.user_security.active_sessions'), $activeSessions)
                ->description(__('dashboard.user_security.session_window', ['minutes' => config('session.lifetime')]))
                ->descriptionIcon(Heroicon::OutlinedComputerDesktop)
                ->color('info'),

            Stat::make(__('dashboard.user_security.two_factor_users'), $twoFactorUsers)
                ->description($totalUsers > 0 ? round(($twoFactorUsers / $totalUsers) * 100).'% '.__('dashboard.user_security.coverage') : '0%')
                ->descriptionIcon(Heroicon::OutlinedShieldCheck)
                ->color($totalUsers > 0 && $twoFactorUsers === $totalUsers ? 'success' : 'warning'),

            Stat::make(__('dashboard.user_security.api_tokens'), $activeApiTokens)
                ->description(__('dashboard.user_security.api_tokens_description'))
                ->descriptionIcon(Heroicon::OutlinedKey)
                ->color('primary'),

            Stat::make(__('dashboard.user_security.active_mobile_devices'), $activeMobileDevices)
                ->description(__('dashboard.user_security.active_mobile_devices_description'))
                ->descriptionIcon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('info'),

            Stat::make(__('dashboard.user_security.compromised_mobile_devices'), $compromisedMobileDevices)
                ->description(__('dashboard.user_security.compromised_mobile_devices_description'))
                ->descriptionIcon(Heroicon::OutlinedShieldExclamation)
                ->color($compromisedMobileDevices > 0 ? 'danger' : 'success'),
        ];
    }
}
