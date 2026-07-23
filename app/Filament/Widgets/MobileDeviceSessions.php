<?php

namespace App\Filament\Widgets;

use App\Enums\DevicePlatform;
use App\Enums\TokenRevokeReason;
use App\Models\ApiDeviceSession;
use App\Services\Auth\MobileTokenService;
use App\Services\Security\SecurityTelemetry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MobileDeviceSessions extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->can('users.view') ?? false;
    }

    public function getTableHeading(): string
    {
        return __('dashboard.mobile_devices.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ApiDeviceSession::query()->with('user.profile')->latest('last_seen_at'))
            ->columns([
                TextColumn::make('user.email')
                    ->label(__('dashboard.mobile_devices.user'))
                    ->description(fn (ApiDeviceSession $record): string => $record->user?->getFilamentName() ?? '—')
                    ->searchable(),
                TextColumn::make('device_name')
                    ->label(__('dashboard.mobile_devices.device'))
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->searchable(),
                TextColumn::make('platform')
                    ->label(__('dashboard.mobile_devices.platform'))
                    ->formatStateUsing(fn (DevicePlatform|string $state): string => $state instanceof DevicePlatform
                        ? match ($state) {
                            DevicePlatform::IOS => 'iOS',
                            DevicePlatform::ANDROID => 'Android',
                        }
                        : (string) $state)
                    ->badge()
                    ->color('info'),
                TextColumn::make('app_version')
                    ->label(__('dashboard.mobile_devices.app_version'))
                    ->placeholder('—'),
                TextColumn::make('ip_address')
                    ->label(__('dashboard.mobile_devices.ip_address'))
                    ->placeholder('—'),
                TextColumn::make('last_seen_at')
                    ->label(__('dashboard.mobile_devices.last_seen'))
                    ->since()
                    ->dateTimeTooltip(),
                TextColumn::make('status')
                    ->label(__('dashboard.mobile_devices.status'))
                    ->state(fn (ApiDeviceSession $record): string => $this->status($record))
                    ->formatStateUsing(fn (string $state): string => __('dashboard.mobile_devices.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'expired' => 'warning',
                        'compromised' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label(__('dashboard.mobile_devices.platform'))
                    ->options([
                        DevicePlatform::IOS->value => 'iOS',
                        DevicePlatform::ANDROID->value => 'Android',
                    ]),
                SelectFilter::make('status')
                    ->label(__('dashboard.mobile_devices.status'))
                    ->options([
                        'active' => __('dashboard.mobile_devices.active'),
                        'expired' => __('dashboard.mobile_devices.expired'),
                        'revoked' => __('dashboard.mobile_devices.revoked'),
                        'compromised' => __('dashboard.mobile_devices.compromised'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query
                                ->whereNull('revoked_at')
                                ->where('refresh_expires_at', '>', now())
                                ->where('absolute_expires_at', '>', now()),
                            'expired' => $query
                                ->whereNull('revoked_at')
                                ->where(fn (Builder $query): Builder => $query
                                    ->where('refresh_expires_at', '<=', now())
                                    ->orWhere('absolute_expires_at', '<=', now())),
                            'revoked' => $query
                                ->whereNotNull('revoked_at')
                                ->where('revoke_reason', '!=', TokenRevokeReason::REFRESH_TOKEN_REUSED->value),
                            'compromised' => $query
                                ->where('revoke_reason', TokenRevokeReason::REFRESH_TOKEN_REUSED->value),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('dashboard.mobile_devices.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->can('users.update') ?? false)
                    ->visible(fn (ApiDeviceSession $record): bool => $record->revoked_at === null
                        && (auth()->user()?->can('users.update') ?? false))
                    ->action(function (ApiDeviceSession $record, MobileTokenService $tokens): void {
                        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');
                        $tokens->revokeDeviceSession($record, TokenRevokeReason::ADMIN_REVOKED);

                        Notification::make()
                            ->title(__('dashboard.mobile_devices.revoked_message'))
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }

    private function status(ApiDeviceSession $session): string
    {
        if ($session->revoked_at !== null) {
            return $session->revoke_reason === TokenRevokeReason::REFRESH_TOKEN_REUSED
                ? 'compromised'
                : 'revoked';
        }

        if ($session->refresh_expires_at->isPast() || $session->absolute_expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
