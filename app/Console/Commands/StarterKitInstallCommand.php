<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Settings;
use App\Support\SettingsDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class StarterKitInstallCommand extends Command
{
    protected $signature = 'app:starter-kit-install
        {--guard=web : Guard name for roles/permissions}
        {--fresh : Delete existing starter-kit data then recreate}
        {--force : Skip confirmations}';

    protected $description = 'Install Douwyn Starter Kit default data.';

    /**
     * @throws Throwable
     */
    public function handle(): int
    {
        $guard = (string) $this->option('guard');

        if ($this->option('fresh') && ! $this->option('force')) {
            if (! $this->confirm('This will delete all data and recreate them. Continue?')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($guard) {
            // ======= Define your defaults here =======
            $roles = [
                'super_admin',
                'admin',
                'staff',
            ];

            $permissions = [
                // Panel
                'panel.access',

                // Users
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'users.assign_roles',

                // Login sessions
                'login_sessions.view',
                'login_sessions.revoke',

                // Roles
                'roles.view',
                'roles.create',
                'roles.update',
                'roles.delete',

                // Permissions
                'permissions.view',
                'permissions.create',
                'permissions.update',
                'permissions.delete',

                // Settings
                'settings.view',
                'settings.update',
                'settings.delete',
                'settings.create',

                // Activity logs
                'activity_logs.view',
                'activity_logs.delete',

                'system.queue.view',
            ];

            $rolePermissions = [
                'super_admin' => ['*'], // special handling
                'admin' => [
                    'panel.access',
                    'users.view', 'users.create', 'users.update', 'users.delete',
                    'login_sessions.view', 'login_sessions.revoke',
                    'roles.view',
                    'permissions.view',
                    'settings.view', 'settings.update',
                    'activity_logs.view',
                ],
                'staff' => [
                    // Application role only. It cannot access the Filament admin panel.
                ],
            ];
            // ========================================

            // Always clear the cache so Spatie sees new perms immediately
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if ($this->option('fresh')) {
                Permission::query()->where('guard_name', $guard)->whereIn('name', $permissions)->delete();
                Role::query()->where('guard_name', $guard)->whereIn('name', $roles)->delete();
            }

            // Create permissions
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            // Create roles
            foreach ($roles as $role) {
                Role::findOrCreate($role, $guard);
            }

            // Assign permissions to roles
            $allPermissions = Permission::query()->where('guard_name', $guard)->pluck('name')->all();

            foreach ($rolePermissions as $roleName => $perms) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', $guard)->firstOrFail();

                if ($perms === ['*']) {
                    $perms = $allPermissions;
                }

                if ($this->option('fresh')) {
                    $role->syncPermissions($perms);

                    continue;
                }

                // Upgrades must not remove project-specific grants from the
                // starter roles. A destructive reset remains available via
                // the explicit --fresh option.
                $role->givePermissionTo($perms);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /**
             * Setting default
             */
            Settings::seedDefaults(SettingsDefaults::all());
        });

        $this->info("✅ Starter Kit installed for guard '$guard'. Roles & permissions are ready.");

        return self::SUCCESS;
    }
}
