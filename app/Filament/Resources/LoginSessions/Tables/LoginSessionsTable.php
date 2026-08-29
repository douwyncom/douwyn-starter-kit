<?php

namespace App\Filament\Resources\LoginSessions\Tables;

use App\Enums\TokenRevokeReason;
use App\Filament\Actions\OpaqueLoginSessionAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\LoginSession;
use App\Services\Security\SecurityTelemetry;
use App\Support\Timezone;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LoginSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user.profile'))
            ->columns([
                TextColumn::make('user.email')
                    ->label(__('resources/login_session.columns.user'))
                    ->description(fn (LoginSession $record): string => $record->user?->getFilamentName() ?? '—')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('device_label')
                    ->label(__('resources/login_session.columns.device'))
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereLike('user_agent', "%$search%", caseSensitive: false)),

                TextColumn::make('ip_address')
                    ->label(__('resources/login_session.columns.ip_address'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),

                TextColumn::make('last_active_at')
                    ->label(__('resources/login_session.columns.last_active'))
                    ->since(fn (): string => Timezone::current())
                    ->dateTimeTooltip(timezone: fn (): string => Timezone::current())
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),

                IconColumn::make('current')
                    ->label(__('resources/login_session.columns.current'))
                    ->boolean()
                    ->state(fn (LoginSession $record): bool => self::isCurrent($record)),

                TextColumn::make('status')
                    ->label(__('resources/login_session.columns.status'))
                    ->state(fn (LoginSession $record): string => self::status($record))
                    ->formatStateUsing(fn (string $state): string => __("resources/login_session.statuses.$state"))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'expired' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label(__('resources/login_session.columns.signed_in_at'))
                    ->dateTime(timezone: fn (): string => Timezone::current())
                    ->fontFamily(FontFamily::Mono)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('revoked_at')
                    ->label(__('resources/login_session.columns.revoked_at'))
                    ->dateTime(timezone: fn (): string => Timezone::current())
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label(__('resources/login_session.filters.user'))
                    ->relationship('user', 'email')
                    ->searchable(),

                SelectFilter::make('status')
                    ->label(__('resources/login_session.filters.status'))
                    ->options([
                        'active' => __('resources/login_session.statuses.active'),
                        'expired' => __('resources/login_session.statuses.expired'),
                        'revoked' => __('resources/login_session.statuses.revoked'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->active(),
                        'expired' => $query
                            ->whereNull('revoked_at')
                            ->where('last_active_at', '<', now()->subMinutes((int) config('session.lifetime', 120))),
                        'revoked' => $query->whereNotNull('revoked_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                OpaqueLoginSessionAction::make('viewUser')
                    ->label(__('resources/login_session.actions.view_user'))
                    ->icon(Heroicon::OutlinedUser)
                    ->url(fn (LoginSession $record): string => UserResource::getUrl('edit', [
                        'record' => $record->user_uuid,
                    ]))
                    ->visible(fn (LoginSession $record): bool => $record->user !== null
                        && (auth()->user()?->can('users.update') ?? false)),

                OpaqueLoginSessionAction::make('revoke')
                    ->label(__('resources/login_session.actions.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->databaseTransaction()
                    ->authorize(fn (LoginSession $record): bool => auth()->user()?->can('revoke', $record) ?? false)
                    ->visible(fn (LoginSession $record): bool => self::canRevoke($record))
                    ->action(function (LoginSession $record): void {
                        if (! self::canRevoke($record)) {
                            return;
                        }

                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                        $record->revoke(TokenRevokeReason::ADMIN_REVOKED->value, 'single_session');

                        Notification::make()
                            ->title(__('resources/login_session.messages.revoked'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('revokeSelected')
                        ->label(__('resources/login_session.actions.revoke_selected'))
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->databaseTransaction()
                        ->authorize(fn (): bool => auth()->user()?->can('login_sessions.revoke') ?? false)
                        ->authorizeIndividualRecords('revoke')
                        ->visible(fn (): bool => auth()->user()?->can('login_sessions.revoke') ?? false)
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                            $revoked = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof LoginSession || ! self::canRevoke($record)) {
                                    continue;
                                }

                                $record->revoke(TokenRevokeReason::ADMIN_REVOKED->value, 'selected_sessions');
                                $revoked++;
                            }

                            Notification::make()
                                ->title(trans_choice(
                                    'resources/login_session.messages.selected_revoked',
                                    $revoked,
                                    ['count' => $revoked],
                                ))
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (LoginSession $record): bool => self::canRevoke($record))
            ->stackedOnMobile()
            ->selectCurrentPageOnly()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('last_active_at', 'desc')
            ->poll('60s');
    }

    private static function canRevoke(LoginSession $record): bool
    {
        return $record->revoked_at === null
            && $record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime', 120)))
            && ! self::isCurrent($record)
            && (auth()->user()?->can('revoke', $record) ?? false);
    }

    private static function isCurrent(LoginSession $record): bool
    {
        $currentSessionId = (string) session()->get(
            'login_session_registry_id',
            session()->getId(),
        );

        return $currentSessionId !== ''
            && hash_equals($currentSessionId, (string) $record->getKey());
    }

    private static function status(LoginSession $record): string
    {
        if ($record->revoked_at !== null) {
            return 'revoked';
        }

        return $record->last_active_at->gte(now()->subMinutes((int) config('session.lifetime', 120)))
            ? 'active'
            : 'expired';
    }
}
