<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('allows an active module role with panel access into the admin panel', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permission = Permission::findOrCreate('panel.access', 'web');
    $role = Role::findOrCreate('hrm_admin', 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);
    $panel = Panel::make()->id('admin');

    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('denies inactive users and users without the panel permission', function (): void {
    $panel = Panel::make()->id('admin');
    $withoutPermission = User::factory()->create();
    $inactive = User::factory()->create(['is_inactive' => true]);
    Permission::findOrCreate('panel.access', 'web');
    $inactive->givePermissionTo('panel.access');

    expect($withoutPermission->canAccessPanel($panel))->toBeFalse()
        ->and($inactive->canAccessPanel($panel))->toBeFalse();
});
