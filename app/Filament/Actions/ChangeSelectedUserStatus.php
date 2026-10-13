<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeSelectedUserStatus
{
    public static function make(bool $inactive): BulkAction
    {
        return BulkAction::make($inactive ? 'deactivateSelected' : 'activateSelected')
            ->label(__($inactive ? 'table.bulk.deactivate' : 'table.bulk.activate'))
            ->icon($inactive ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color($inactive ? 'danger' : 'success')
            ->requiresConfirmation()
            ->authorize(fn (): bool => self::canChange())
            ->visible(fn (): bool => self::canChange())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($inactive): void {
                self::change($records, $inactive);
                Notification::make()->title(__('table.bulk.status_updated'))->success()->send();
            });
    }

    public static function change(Collection $records, bool $inactive): void
    {
        abort_unless(self::canChange(), 403);

        if ($records->isEmpty() || $records->count() > 1000 || $records->contains(fn ($record): bool => ! $record instanceof User)) {
            throw ValidationException::withMessages(['records' => __('table.bulk.selection_limit')]);
        }

        DB::transaction(function () use ($records, $inactive): void {
            $administrators = User::activeSuperAdministrators()->orderBy('uuid')->lockForUpdate()->get();
            $users = User::query()->whereKey($records->map(fn (User $user): string => $user->getKey()))
                ->orderBy('uuid')->lockForUpdate()->get();

            abort_unless($users->count() === $records->count(), 403);

            foreach ($users as $user) {
                abort_unless(UserResource::canView($user) && UserResource::canEdit($user), 403);
            }

            if ($users->contains(fn (User $user): bool => $user->is(auth()->user()))
                || ($inactive && $administrators->isNotEmpty()
                    && $administrators->every(fn (User $admin): bool => $users->contains(fn (User $user): bool => $user->is($admin))))) {
                throw ValidationException::withMessages(['records' => __('table.bulk.protected_users')]);
            }

            request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');

            foreach ($users as $user) {
                if ($user->is_inactive === $inactive) {
                    continue;
                }

                // Model hooks revoke browser sessions and API tokens on deactivation.
                $user->update(['is_inactive' => $inactive]);
                app(SecurityTelemetry::class)->securityChanged(
                    $user, request(), $inactive ? 'account_deactivated' : 'account_activated',
                );
            }
        });
    }

    private static function canChange(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->is_inactive && $user->can('panel.access')
            && $user->can('users.update') && $user->can('users.view');
    }
}
