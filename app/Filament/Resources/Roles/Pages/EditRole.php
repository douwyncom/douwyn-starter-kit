<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function beforeSave(): void
    {
        /** @var Role $record */
        $record = $this->record;

        if ($record->isSystemRole()) {
            $submittedName = (string) ($this->data['name'] ?? $record->name);
            $submittedGuard = (string) ($this->data['guard_name'] ?? $record->guard_name);

            if ($submittedName !== $record->name || $submittedGuard !== $record->guard_name) {
                throw ValidationException::withMessages([
                    'data.name' => __('System role names and guards cannot be changed.'),
                ]);
            }
        }

        if ($record->name !== 'super_admin') {
            return;
        }

        $panelAccess = Permission::query()
            ->where('name', 'panel.access')
            ->where('guard_name', 'web')
            ->first();
        $selectedPermissions = array_map('strval', (array) ($this->data['permissions'] ?? []));

        if ($panelAccess && ! in_array((string) $panelAccess->getKey(), $selectedPermissions, true)) {
            throw ValidationException::withMessages([
                'data.permissions' => __('The super administrator role must retain panel access.'),
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->databaseTransaction()
                ->visible(fn () => auth()->user()?->hasRole(['super_admin']) ?? false),
        ];
    }
}
