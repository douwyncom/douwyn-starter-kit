<?php

namespace App\Filament\Clusters\Account\Pages;

use App\Enums\TokenRevokeReason;
use App\Filament\Actions\OpaqueLoginSessionAction;
use App\Filament\Clusters\Account\AccountCluster;
use App\Filament\Concerns\UsesOpaqueLoginSessionTableKeys;
use App\Models\LoginSession;
use App\Services\Security\SecurityTelemetry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class Sessions extends Page implements HasTable
{
    use InteractsWithTable, UsesOpaqueLoginSessionTableKeys {
        UsesOpaqueLoginSessionTableKeys::getTableRecordKey insteadof InteractsWithTable;
        UsesOpaqueLoginSessionTableKeys::resolveTableRecord insteadof InteractsWithTable;
    }

    protected string $view = 'filament.clusters.account.pages.sessions';

    protected static ?string $cluster = AccountCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('pages/account.sessions.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('pages/account.sessions.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('pages/account.sessions.subheading');
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LoginSession::query()->where('user_uuid', auth()->id()))
            ->columns([
                TextColumn::make('device_label')
                    ->label(__('pages/account.sessions.device'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->description(fn (LoginSession $record): string => mb_strimwidth((string) $record->user_agent, 0, 90, '…')),
                TextColumn::make('ip_address')
                    ->label(__('pages/account.sessions.ip_address'))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                TextColumn::make('last_active_at')
                    ->label(__('pages/account.sessions.last_active'))
                    ->since()
                    ->dateTimeTooltip()
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),
                IconColumn::make('current')
                    ->label(__('pages/account.sessions.current'))
                    ->boolean()
                    ->state(fn (LoginSession $record): bool => $this->isCurrent($record)),
                TextColumn::make('revoked_at')
                    ->label(__('pages/account.sessions.status'))
                    ->formatStateUsing(fn (LoginSession $record): string => $record->revoked_at
                        ? __('pages/account.sessions.revoked')
                        : ($record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime')))
                            ? __('pages/account.sessions.active')
                            : __('dashboard.recent_sessions.expired')))
                    ->badge()
                    ->color(fn (LoginSession $record): string => $record->revoked_at
                        ? 'gray'
                        : ($record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime'))) ? 'success' : 'warning')),
            ])
            ->recordActions([
                OpaqueLoginSessionAction::make('revoke')
                    ->label(__('pages/account.sessions.revoke'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (LoginSession $record): bool => $record->revoked_at === null)
                    ->action(function (LoginSession $record): void {
                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                        $isCurrent = $this->isCurrent($record);
                        $record->revoke(TokenRevokeReason::USER_REVOKED->value, 'single_session');

                        if ($isCurrent) {
                            auth()->logout();
                            session()->invalidate();
                            session()->regenerateToken();
                            redirect()->to(Filament::getLoginUrl());

                            return;
                        }

                        Notification::make()->title(__('pages/account.sessions.revoked_message'))->success()->send();
                    }),
            ])
            ->defaultSort('last_active_at', 'desc');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revokeOthers')
                ->label(__('pages/account.sessions.revoke_others'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                    $revokedCount = LoginSession::query()
                        ->where('user_uuid', auth()->id())
                        ->where('id', '!=', $this->currentSessionId())
                        ->notRevoked()
                        ->update(['revoked_at' => now()]);
                    $user = auth()->user();
                    $user?->invalidateRememberedLogin();

                    if ($user) {
                        app(SecurityTelemetry::class)->browserSessionsRevoked(
                            $user,
                            request(),
                            'other_sessions',
                            $revokedCount,
                        );
                    }

                    Notification::make()->title(__('pages/account.sessions.others_revoked'))->success()->send();
                }),
        ];
    }

    private function isCurrent(LoginSession $session): bool
    {
        $currentSessionId = $this->currentSessionId();

        return $currentSessionId !== ''
            && hash_equals($currentSessionId, (string) $session->getKey());
    }

    private function currentSessionId(): string
    {
        return (string) session()->get(
            'login_session_registry_id',
            session()->getId(),
        );
    }
}
