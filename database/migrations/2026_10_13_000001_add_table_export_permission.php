<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('tables.export', 'web');

        foreach (Role::query()->where('guard_name', 'web')->whereIn('name', ['admin', 'super_admin'])->get() as $role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', 'tables.export')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
