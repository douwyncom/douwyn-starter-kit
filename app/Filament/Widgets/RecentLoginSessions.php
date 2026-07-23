<?php

namespace App\Filament\Widgets;

use App\Enums\TokenRevokeReason;
use App\Filament\Actions\OpaqueLoginSessionAction;
use App\Filament\Concerns\UsesOpaqueLoginSessionTableKeys;
use App\Filament\Resources\LoginSessions\LoginSessionResource;
use App\Models\LoginSession;
use App\Services\Security\SecurityTelemetry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentLoginSessions extends TableWidget
{
    use UsesOpaqueLoginSessionTableKeys;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('login_sessions.view') ?? false;
    }

    public function getTableHeading(): string
    {
        return __('dashboard.recent_sessions.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LoginSession::query()->with('user.profile')->latest('last_active_at'))
            ->columns([
                TextColumn::make('user.email')
                    ->label(__('dashboard.recent_sessions.user'))
                    ->description(fn (LoginSession $record): string => $record->user?->getFilamentName() ?? '—')
                    ->searchable(),
                TextColumn::make('device_label')
                    ->label(__('dashboard.recent_sessions.device'))
                    ->icon(Heroicon::OutlinedComputerDesktop),
                TextColumn::make('ip_address')
                    ->label(__('dashboard.recent_sessions.ip_address')),
                TextColumn::make('last_active_at')
                    ->label(__('dashboard.recent_sessions.last_active'))
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('revoked_at')
                    ->label(__('dashboard.recent_sessions.status'))
                    ->formatStateUsing(fn (LoginSession $record): string => $record->revoked_at ? __('dashboard.recent_sessions.revoked') : ($record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime'))) ? __('dashboard.recent_sessions.active') : __('dashboard.recent_sessions.expired')))
                    ->badge()
                    ->color(fn (LoginSession $record): string => $record->revoked_at ? 'gray' : ($record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime'))) ? 'success' : 'warning')),
            ])
            ->recordActions([
                OpaqueLoginSessionAction::make('revoke')
                    ->label(__('dashboard.recent_sessions.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('login_sessions.revoke') ?? false)
                    ->visible(fn (LoginSession $record): bool => $record->revoked_at === null
                        && ! $this->isCurrent($record)
                        && (auth()->user()?->can('login_sessions.revoke') ?? false))
                    ->action(function (LoginSession $record): void {
                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                        $record->revoke(TokenRevokeReason::ADMIN_REVOKED->value, 'single_session');
                        Notification::make()->title(__('dashboard.recent_sessions.revoked_message'))->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('manageAll')
                    ->label(__('resources/login_session.actions.manage_all'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => LoginSessionResource::getUrl())
                    ->visible(fn (): bool => auth()->user()?->can('login_sessions.view') ?? false),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }

    private function isCurrent(LoginSession $session): bool
    {
        $currentSessionId = (string) session()->get(
            'login_session_registry_id',
            session()->getId(),
        );

        return $currentSessionId !== ''
            && hash_equals($currentSessionId, (string) $session->getKey());
    }
}
