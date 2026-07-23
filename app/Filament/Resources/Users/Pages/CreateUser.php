<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $selectedRoleIds = array_map('strval', (array) ($this->data['roles'] ?? []));

        if ($selectedRoleIds === []) {
            return;
        }

        $actor = auth()->user() ?? abort(403);

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
}
