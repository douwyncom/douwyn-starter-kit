<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('installs roles and dedicated activity log permissions', function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();

    $superAdmin = Role::findByName('super_admin');
    $admin = Role::findByName('admin');
    $staff = Role::findByName('staff');

    expect(Permission::findByName('activity_logs.view'))->not->toBeNull()
        ->and(Permission::findByName('activity_logs.delete'))->not->toBeNull()
        ->and(Permission::findByName('login_sessions.view'))->not->toBeNull()
        ->and(Permission::findByName('login_sessions.revoke'))->not->toBeNull()
        ->and($superAdmin->hasPermissionTo('activity_logs.delete'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('users.assign_roles'))->toBeTrue()
        ->and($admin->hasPermissionTo('users.assign_roles'))->toBeFalse()
        ->and($admin->hasPermissionTo('roles.update'))->toBeFalse()
        ->and($admin->hasPermissionTo('permissions.create'))->toBeFalse()
        ->and($admin->hasPermissionTo('login_sessions.view'))->toBeTrue()
        ->and($admin->hasPermissionTo('login_sessions.revoke'))->toBeTrue()
        ->and($admin->hasPermissionTo('activity_logs.delete'))->toBeFalse()
        ->and($staff->hasPermissionTo('panel.access'))->toBeFalse()
        ->and($staff->hasPermissionTo('activity_logs.view'))->toBeFalse()
        ->and($staff->hasPermissionTo('login_sessions.view'))->toBeFalse()
        ->and($staff->hasPermissionTo('login_sessions.revoke'))->toBeFalse()
        ->and($staff->hasPermissionTo('activity_logs.delete'))->toBeFalse();
});

it('adds new defaults on upgrade without removing project permissions', function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();

    $customPermission = Permission::findOrCreate('orders.manage', 'web');
    $admin = Role::findByName('admin');
    $staff = Role::findByName('staff');
    $admin->givePermissionTo($customPermission);
    $staff->givePermissionTo($customPermission);

    $this->artisan('app:starter-kit-install --force')->assertSuccessful();

    expect($admin->fresh()->hasPermissionTo('orders.manage'))->toBeTrue()
        ->and($staff->fresh()->hasPermissionTo('orders.manage'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('login_sessions.view'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('login_sessions.revoke'))->toBeTrue();
});
