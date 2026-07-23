<?php

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Enums\SecurityEvent;
use App\Enums\TokenRevokeReason;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\MobileDeviceSessions;
use App\Filament\Widgets\RecentLoginSessions;
use App\Filament\Widgets\UserSecurityStats;
use App\Models\ApiDeviceSession;
use App\Models\LoginSession;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use App\Services\Security\SecurityTelemetry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

it('translates user menu labels using the current locale', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app()->setLocale('en');
    $items = Filament::getUserMenuItems();

    expect($items['profile']->getLabel())->toBe('Profile')
        ->and($items['password-change']->getLabel())->toBe('Change password')
        ->and($items['Security']->getLabel())->toBe('Security')
        ->and($items['sessions']->getLabel())->toBe('Login sessions')
        ->and($items['api-documentation']->getLabel())->toBe('API Documentation');

    app()->setLocale('vi');

    expect($items['profile']->getLabel())->toBe('Hồ sơ')
        ->and($items['password-change']->getLabel())->toBe('Thay đổi mật khẩu')
        ->and($items['Security']->getLabel())->toBe('Bảo mật')
        ->and($items['sessions']->getLabel())->toBe('Phiên đăng nhập')
        ->and($items['api-documentation']->getLabel())->toBe('Tài liệu API');
});

it('renders user management and dashboard widgets for an authorized administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get(UserResource::getUrl())
        ->assertOk()
        ->assertSee($admin->email);

    $this->get('/admin')->assertOk();

    Livewire::test(UserSecurityStats::class)
        ->assertSee(__('dashboard.user_security.heading'));

    Livewire::test(RecentLoginSessions::class)
        ->assertSee(__('dashboard.recent_sessions.heading'));

    Livewire::test(MobileDeviceSessions::class)
        ->assertSee(__('dashboard.mobile_devices.heading'));
});

it('renders mobile security stats and lets an authorized administrator revoke a device', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $applicationUser = User::factory()->create();
    $deviceId = (string) Str::uuid();
    $tokens = app(MobileTokenService::class);
    $pair = $tokens->issue($applicationUser, new MobileDeviceData(
        deviceName: 'Managed iPhone',
        deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
        platform: DevicePlatform::IOS,
        appVersion: '2.4.0',
        ipAddress: '203.0.113.40',
        userAgent: 'DouwynMobile/2.4 iOS',
    ));

    $compromisedUser = User::factory()->create();
    $compromised = $tokens->issue($compromisedUser, new MobileDeviceData(
        deviceName: 'Compromised Android',
        deviceIdHash: app(DeviceFingerprint::class)->hash((string) Str::uuid()),
        platform: DevicePlatform::ANDROID,
        appVersion: '3.0.0',
        ipAddress: '198.51.100.40',
        userAgent: 'DouwynMobile/3.0 Android',
    ));
    $tokens->revokeDeviceSession($compromised->deviceSession, TokenRevokeReason::REFRESH_TOKEN_REUSED);

    $this->actingAs($admin);

    Livewire::test(UserSecurityStats::class)
        ->assertSee(__('dashboard.user_security.active_mobile_devices'))
        ->assertSee(__('dashboard.user_security.compromised_mobile_devices'));

    Livewire::test(MobileDeviceSessions::class)
        ->assertCanSeeTableRecords([$pair->deviceSession, $compromised->deviceSession])
        ->assertTableFilterExists('platform')
        ->assertTableFilterExists('status')
        ->filterTable('platform', DevicePlatform::IOS)
        ->assertCanSeeTableRecords([$pair->deviceSession])
        ->assertCanNotSeeTableRecords([$compromised->deviceSession])
        ->resetTableFilters()
        ->filterTable('status', 'compromised')
        ->assertCanSeeTableRecords([$compromised->deviceSession])
        ->assertCanNotSeeTableRecords([$pair->deviceSession]);

    Livewire::test(MobileDeviceSessions::class)
        ->assertTableActionVisible('revoke', $pair->deviceSession)
        ->callTableAction('revoke', $pair->deviceSession)
        ->assertNotified(__('dashboard.mobile_devices.revoked_message'));

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::ADMIN_REVOKED)
        ->and(PersonalAccessToken::findToken($pair->accessToken))->toBeNull()
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $pair->deviceSession->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeFalse();
});

it('hides mobile device revocation from a read-only user manager', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');
    $applicationUser = User::factory()->create();
    $pair = app(MobileTokenService::class)->issue($applicationUser, new MobileDeviceData(
        deviceName: 'Read-only Android',
        deviceIdHash: app(DeviceFingerprint::class)->hash((string) Str::uuid()),
        platform: DevicePlatform::ANDROID,
        appVersion: null,
        ipAddress: null,
        userAgent: null,
    ));

    $this->actingAs($viewer);

    Livewire::test(MobileDeviceSessions::class)
        ->assertCanSeeTableRecords([$pair->deviceSession])
        ->assertTableActionHidden('revoke', $pair->deviceSession);

    expect(ApiDeviceSession::query()->findOrFail($pair->deviceSession->getKey())->revoked_at)->toBeNull();
});

it('protects the final super administrator from deletion', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $operator = User::factory()->create();
    $operator->givePermissionTo('users.delete');

    expect((new UserPolicy)->delete($operator, $superAdmin))->toBeFalse();

    $secondSuperAdmin = User::factory()->create();
    $secondSuperAdmin->assignRole('super_admin');

    expect((new UserPolicy)->delete($operator, $superAdmin))->toBeTrue()
        ->and((new UserPolicy)->delete($superAdmin, $superAdmin))->toBeFalse();
});

it('creates a user with profile and role from the management resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $staffRole = Role::findByName('staff');

    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'email' => 'Managed.User@Example.com',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'roles' => [$staffRole->getKey()],
            'is_inactive' => false,
            'profile' => [
                'first_name' => 'Managed',
                'last_name' => 'User',
                'locale' => 'vi',
                'timezone' => 'Asia/Ho_Chi_Minh',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'managed.user@example.com')->firstOrFail();

    expect($user->profile?->first_name)->toBe('Managed')
        ->and($user->hasRole('staff'))->toBeTrue();
});

it('uses the full content width and a validated timezone select on the user form', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $user = User::factory()->create();
    $originalTimezone = $user->profile->timezone;

    $this->actingAs($admin);

    $component = Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertFormFieldExists('profile.timezone', fn ($field): bool => $field instanceof Select
            && $field->isSearchable()
            && $field->isRequired()
            && isset($field->getOptions()['UTC'], $field->getOptions()['Asia/Ho_Chi_Minh']));

    $rootGrid = $component->instance()->form->getComponents()[0] ?? null;

    expect($rootGrid)->toBeInstanceOf(Grid::class)
        ->and($rootGrid->getColumnSpan('default'))->toBe('full')
        ->and($rootGrid->getColumns('lg'))->toBeNull()
        ->and($rootGrid->getColumns('xl'))->toBe(3);

    $component
        ->set('data.profile.timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasFormErrors(['profile.timezone']);

    expect($user->profile->fresh()->timezone)->toBe($originalTimezone);
});

it('revokes every mobile credential when an administrator resets a user password', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $user = User::factory()->create(['password' => 'Secret123']);
    $deviceId = (string) Str::uuid();
    $pair = app(MobileTokenService::class)->issue($user, new MobileDeviceData(
        deviceName: 'Password reset phone',
        deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
        platform: DevicePlatform::IOS,
        appVersion: '1.0.0',
        ipAddress: '203.0.113.50',
        userAgent: 'ManagedMobile/1.0',
    ));

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->set('data.password', 'Changed456')
        ->set('data.password_confirmation', 'Changed456')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::PASSWORD_CHANGED)
        ->and(PersonalAccessToken::findToken($pair->accessToken))->toBeNull()
        ->and(RefreshToken::query()
            ->where('api_device_session_id', $pair->deviceSession->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::PASSWORD_CHANGED->value)
            ->where('subject_id', $user->uuid)
            ->where('properties->channel', 'filament_admin')
            ->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $pair->refreshToken,
        'device_id' => $deviceId,
    ])->assertUnauthorized();
});

it('tracks and enforces revoked login sessions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/admin')->assertOk();

    $loginSession = LoginSession::query()->where('user_uuid', $admin->uuid)->first();

    expect($loginSession)->not->toBeNull()
        ->and($loginSession->ip_address)->not->toBeNull();

    $loginSession->revoke();

    $this->get('/admin')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('lists login sessions in the account area', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin/account/sessions')->assertOk();

    expect(LoginSession::query()->where('user_uuid', $user->uuid)->exists())->toBeTrue();
});

it('does not count inactive accounts when protecting the final super administrator', function () {
    $activeSuperAdmin = User::factory()->create();
    $activeSuperAdmin->assignRole('super_admin');
    $inactiveSuperAdmin = User::factory()->create(['is_inactive' => true]);
    $inactiveSuperAdmin->assignRole('super_admin');
    $operator = User::factory()->create();
    $operator->givePermissionTo('users.delete');

    expect((new UserPolicy)->delete($operator, $activeSuperAdmin))->toBeFalse();
});

it('blocks a default admin from editing roles through a direct url', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $superAdminRole = Role::findByName('super_admin');

    $this->actingAs($admin)
        ->get(RoleResource::getUrl('edit', ['record' => $superAdminRole]))
        ->assertForbidden();

    expect((new RolePolicy)->update($admin, $superAdminRole))->toBeFalse()
        ->and((new RolePolicy)->delete($admin, $superAdminRole))->toBeFalse();
});

it('prevents a default admin from assigning an administrative role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $superAdminRole = Role::findByName('super_admin');

    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->set('data.email', 'escalation@example.com')
        ->set('data.password', 'Secret123')
        ->set('data.password_confirmation', 'Secret123')
        ->set('data.roles', [$superAdminRole->getKey()])
        ->set('data.is_inactive', false)
        ->set('data.profile.first_name', 'Privilege')
        ->set('data.profile.last_name', 'Escalation')
        ->set('data.profile.locale', 'en')
        ->set('data.profile.timezone', 'UTC')
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::query()->where('email', 'escalation@example.com')->exists())->toBeFalse();
});

it('protects system role identity and required panel access server side', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');
    $role = Role::findByName('super_admin');
    $permissionsWithoutPanel = $role->permissions()
        ->where('name', '!=', 'panel.access')
        ->pluck('permissions.uuid')
        ->all();

    $this->actingAs($superAdmin);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->set('data.name', 'root')
        ->set('data.permissions', $permissionsWithoutPanel)
        ->call('save')
        ->assertHasFormErrors(['name']);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->set('data.permissions', $permissionsWithoutPanel)
        ->call('save')
        ->assertHasFormErrors(['permissions']);

    expect($role->fresh()->name)->toBe('super_admin')
        ->and($role->fresh()->hasPermissionTo('panel.access'))->toBeTrue()
        ->and((new RolePolicy)->delete($superAdmin, $role))->toBeFalse();
});

it('requires the user management permission even when editing yourself', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');

    expect((new UserPolicy)->update($viewer, $viewer))->toBeFalse();
});

it('does not create a web session for an application user on the admin login page', function () {
    $applicationUser = User::factory()->create(['password' => 'Secret123']);
    $applicationUser->assignRole('staff');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(Login::class)
        ->set('data.credentials.email', $applicationUser->email)
        ->set('data.credentials.password', 'Secret123')
        ->call('submitCredentials')
        ->assertHasErrors(['data.credentials.email']);

    $this->assertGuest();
});
