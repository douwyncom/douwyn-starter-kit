<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\TokenRevokeReason;
use App\Enums\TwoFactorMethod;
use App\Filament\Resources\LoginSessions\LoginSessionResource;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['profile', 'roles'])
                ->withCount([
                    'loginSessions as active_sessions_count' => fn (Builder $query) => $query->active(),
                    'tokens as api_tokens_count' => fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                ]))
            ->columns([
                TextColumn::make('profile.first_name')
                    ->label(__('resources/user.columns.name'))
                    ->formatStateUsing(fn (User $record): string => trim(($record->profile?->first_name ?? '').' '.($record->profile?->last_name ?? '')) ?: '—')
                    ->weight(FontWeight::SemiBold),

                TextColumn::make('email')
                    ->label(__('resources/user.fields.email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('roles.name')
                    ->label(__('resources/user.fields.roles'))
                    ->badge()
                    ->separator(','),

                IconColumn::make('is_inactive')
                    ->label(__('resources/user.columns.active'))
                    ->boolean()
                    ->state(fn (User $record): bool => ! $record->is_inactive),

                TextColumn::make('two_factor_method')
                    ->label(__('resources/user.columns.two_factor'))
                    ->formatStateUsing(function (TwoFactorMethod|string|null $state): string {
                        if ($state instanceof TwoFactorMethod) {
                            return $state->getLabel();
                        }

                        return TwoFactorMethod::tryFrom((string) $state)?->getLabel() ?? __('enum.none');
                    })
                    ->badge()
                    ->color(fn (User $record): string => $record->hasEnabledTwoFactor() ? 'success' : 'gray'),

                TextColumn::make('active_sessions_count')
                    ->label(__('resources/user.columns.sessions'))
                    ->badge()
                    ->color('info')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('api_tokens_count')
                    ->label(__('resources/user.columns.api_tokens'))
                    ->badge()
                    ->color('primary')
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('table.created_at'))
                    ->dateTime()
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('resources/user.fields.roles'))
                    ->relationship('roles', 'name'),
                TernaryFilter::make('is_inactive')
                    ->label(__('resources/user.fields.is_inactive')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleStatus')
                    ->label(fn (User $record): string => $record->is_inactive ? __('resources/user.actions.activate') : __('resources/user.actions.deactivate'))
                    ->icon(fn (User $record) => $record->is_inactive ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
                    ->color(fn (User $record): string => $record->is_inactive ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->databaseTransaction()
                    ->authorize(fn (User $record): bool => auth()->id() !== $record->getKey()
                        && (auth()->user()?->can('users.update') ?? false))
                    ->visible(fn (User $record): bool => auth()->id() !== $record->getKey() && (auth()->user()?->can('users.update') ?? false))
                    ->action(function (User $record): void {
                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');

                        if (! $record->is_inactive && $record->hasRole('super_admin') && User::activeSuperAdministrators()->lockForUpdate()->get()->count() <= 1) {
                            Notification::make()->title(__('resources/user.messages.last_super_admin'))->danger()->send();

                            return;
                        }

                        $record->update(['is_inactive' => ! $record->is_inactive]);
                        app(SecurityTelemetry::class)->securityChanged(
                            $record,
                            request(),
                            $record->is_inactive ? 'account_deactivated' : 'account_activated',
                        );

                        Notification::make()->title(__('resources/user.messages.status_updated'))->success()->send();
                    }),
                Action::make('manageSessions')
                    ->label(__('resources/user.actions.manage_sessions'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->url(fn (User $record): string => LoginSessionResource::getUrl('index', [
                        'filters' => [
                            'user' => ['value' => $record->getKey()],
                        ],
                    ]))
                    ->visible(fn (): bool => auth()->user()?->can('login_sessions.view') ?? false),
                Action::make('revokeSessions')
                    ->label(__('resources/user.actions.revoke_sessions'))
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->databaseTransaction()
                    ->authorize(fn (): bool => auth()->user()?->can('login_sessions.revoke') ?? false)
                    ->visible(fn (User $record): bool => self::hasRevocableBrowserSessions($record)
                        && (auth()->user()?->can('login_sessions.revoke') ?? false))
                    ->action(function (User $record): void {
                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                        $sessions = $record->loginSessions()->notRevoked();
                        $scope = 'all_sessions';

                        if ($record->is(auth()->user())) {
                            $sessions->whereKeyNot(self::currentSessionId());
                            $scope = 'other_sessions';
                        }

                        $revokedCount = $sessions->update(['revoked_at' => now()]);

                        if ($revokedCount > 0) {
                            $record->invalidateRememberedLogin();
                        }

                        app(SecurityTelemetry::class)->browserSessionsRevoked(
                            $record,
                            request(),
                            $scope,
                            $revokedCount,
                            reason: TokenRevokeReason::ADMIN_REVOKED->value,
                        );
                        Notification::make()->title(__('resources/user.messages.sessions_revoked'))->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function hasRevocableBrowserSessions(User $user): bool
    {
        $sessions = $user->loginSessions()->active();

        if ($user->is(auth()->user())) {
            $sessions->whereKeyNot(self::currentSessionId());
        }

        return $sessions->exists();
    }

    private static function currentSessionId(): string
    {
        return (string) session()->get(
            'login_session_registry_id',
            session()->getId(),
        );
    }
}
