<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\TokenRevokeReason;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AccessRevocationService;
use App\Services\Security\SecurityTelemetry;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private bool $passwordWasChanged = false;

    protected function beforeSave(): void
    {
        /** @var User $record */
        $record = $this->record;
        $this->passwordWasChanged = filled($this->data['password'] ?? null);

        $this->authorizeRoleAssignment($record);

        if ($record->is(auth()->user()) && ($this->data['is_inactive'] ?? false)) {
            throw ValidationException::withMessages([
                'data.is_inactive' => __('resources/user.messages.cannot_deactivate_self'),
            ]);
        }

        if (
            ($this->data['is_inactive'] ?? false) &&
            $record->hasRole('super_admin') &&
            $this->isOnlyActiveSuperAdministrator($record)
        ) {
            throw ValidationException::withMessages([
                'data.is_inactive' => __('resources/user.messages.last_super_admin'),
            ]);
        }

        $superAdminRole = Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first();
        $selectedRoles = array_map('strval', (array) ($this->data['roles'] ?? []));

        if (
            $superAdminRole &&
            $record->hasRole('super_admin') &&
            ! in_array((string) $superAdminRole->getKey(), $selectedRoles, true) &&
            $this->isOnlyActiveSuperAdministrator($record)
        ) {
            throw ValidationException::withMessages([
                'data.roles' => __('resources/user.messages.last_super_admin'),
            ]);
        }
    }

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->record;

        if (! $this->passwordWasChanged) {
            return;
        }

        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');

        if ($record->is(auth()->user())) {
            app(AccessRevocationService::class)->revokeOtherAccess(
                $record,
                request(),
                TokenRevokeReason::PASSWORD_CHANGED,
            );
        } else {
            app(AccessRevocationService::class)->revokeAll(
                $record,
                TokenRevokeReason::PASSWORD_CHANGED,
            );
        }

        app(SecurityTelemetry::class)->passwordChanged(
            $record,
            request(),
            'filament_admin',
        );
    }

    private function authorizeRoleAssignment(User $record): void
    {
        if (! array_key_exists('roles', $this->data)) {
            return;
        }

        $actor = auth()->user() ?? abort(403);
        $selectedRoleIds = array_map('strval', (array) ($this->data['roles'] ?? []));
        $currentRoleIds = $record->roles()->pluck('roles.uuid')->map(fn ($id): string => (string) $id)->all();

        sort($selectedRoleIds);
        sort($currentRoleIds);

        if ($selectedRoleIds === $currentRoleIds) {
            return;
        }

        if (! $actor->can('users.assign_roles')) {
            throw ValidationException::withMessages([
                'data.roles' => __('You are not authorized to assign roles.'),
            ]);
        }

        $assignsSystemRole = Role::query()
            ->whereKey($selectedRoleIds)
            ->whereIn('name', Role::SYSTEM_ROLES)
            ->exists();

        if ($assignsSystemRole && ! $actor->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'data.roles' => __('Only a super administrator can assign administrative roles.'),
            ]);
        }
    }

    private function isOnlyActiveSuperAdministrator(User $record): bool
    {
        if ($record->is_inactive || ! $record->hasRole('super_admin')) {
            return false;
        }

        return User::activeSuperAdministrators()
            ->lockForUpdate()
            ->get()
            ->count() <= 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->databaseTransaction()
                ->before(function (User $record): void {
                    if ($record->hasRole('super_admin')
                        && User::activeSuperAdministrators()->lockForUpdate()->get()->count() <= 1) {
                        throw ValidationException::withMessages([
                            'record' => __('resources/user.messages.last_super_admin'),
                        ]);
                    }
                }),
        ];
    }
}
