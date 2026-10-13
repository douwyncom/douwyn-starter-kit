<?php

namespace App\Providers\Filament;

use App\Filament\Clusters\Account\Pages\Password;
use App\Filament\Clusters\Account\Pages\Profile;
use App\Filament\Clusters\Account\Pages\Security;
use App\Filament\Clusters\Account\Pages\Sessions;
use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\MobileDeviceSessions;
use App\Filament\Widgets\RecentLoginSessions;
use App\Filament\Widgets\RecentSecurityEvents;
use App\Filament\Widgets\SecurityEventStats;
use App\Filament\Widgets\UserSecurityStats;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\TrackLoginSession;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('Douwyn')
            ->brandLogo(fn (): View => view('filament.components.brand'))
            ->brandLogoHeight('2rem')
            ->colors([
                'primary' => Color::Neutral,
                'secondary' => Color::Neutral,
                'gray' => Color::Neutral,
                'danger' => Color::Neutral,
                'success' => Color::Neutral,
                'warning' => Color::Neutral,
                'info' => Color::Neutral,
            ])
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->monoFont('IBM Plex Mono', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->maxContentWidth(Width::Full)
            ->simplePageMaxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(fn (): string => __('starter-kit-domains::domains.navigation.group'))
                    ->collapsed(),
                NavigationGroup::make()
                    ->label(fn (): string => __('starter-kit-telegram::telegram.navigation.group'))
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('WhatsApp')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label(fn (): string => __('starter-kit-licensing::licensing.navigation.group'))
                    ->collapsed(),
                NavigationGroup::make()
                    ->label(fn (): string => __('resources/user.navigation_group')),
                NavigationGroup::make()
                    ->label(fn (): string => __('resources/activity_log.navigation_group'))
                    ->collapsed(),
                NavigationGroup::make()
                    ->label(fn (): string => __('pages/settings.cluster'))
                    ->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                UserSecurityStats::class,
                RecentLoginSessions::class,
                MobileDeviceSessions::class,
                SecurityEventStats::class,
                RecentSecurityEvents::class,
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->userMenuItems([
                Action::make('profile')
                    ->label(fn (): string => __('pages/account.profile.title'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => Profile::getUrl()),
                Action::make('password-change')
                    ->label(fn (): string => __('pages/account.password.change_password'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->url(fn (): string => Password::getUrl()),
                Action::make('Security')
                    ->label(fn (): string => __('pages/account.security.title'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url(fn (): string => Security::getUrl()),
                Action::make('sessions')
                    ->label(fn (): string => __('pages/account.sessions.title'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->url(fn (): string => Sessions::getUrl()),
                Action::make('api-documentation')
                    ->label(fn (): string => __('pages/account.api_documentation'))
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->url(fn (): string => route('api-docs.ui'))
                    ->openUrlInNewTab(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocaleMiddleware::class,
            ])
            ->persistentMiddleware([
                SetLocaleMiddleware::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                TrackLoginSession::class,
            ], isPersistent: true)
            ->databaseTransactions();
    }
}
