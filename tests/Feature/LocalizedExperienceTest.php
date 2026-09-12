<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Filament\Clusters\Account\AccountCluster;
use App\Filament\Clusters\Account\Pages\Profile;
use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\LoginSessions\LoginSessionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\LoginSession;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use App\Support\EmailTwoFactor;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('persists the requested API language when registering a mobile user', function (): void {
    Notification::fake();

    $response = $this->withHeader('Accept-Language', 'vi-VN,vi;q=0.9')
        ->postJson('/api/v1/auth/token/register', [
            'email' => 'localized-user@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'first_name' => 'Ngọc',
            'last_name' => 'Nguyễn',
            'device_name' => 'iPhone',
            'device_id' => (string) Str::uuid(),
            'platform' => 'ios',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', trans('auth.messages.account_created', locale: 'vi'));

    $user = User::query()->where('email', 'localized-user@example.com')->firstOrFail();

    expect($user->profile?->locale)->toBe('vi');
});

it('uses the configured language for public pages and users without a profile preference', function (): void {
    Settings::set('general', 'locale', 'vi');

    $user = User::factory()->create();
    $user->profile->update(['locale' => null]);
    $user->unsetRelation('profile');

    expect($user->preferredLocale())->toBe('vi');

    $this->get('/')
        ->assertOk()
        ->assertSeeText(trans('welcome.heading', locale: 'vi'));
});

it('localizes an early stateful frontend rejection before authentication runs', function (): void {
    $this->withHeader('Accept-Language', 'vi')
        ->postJson('/api/v1/auth/session/login', [])
        ->assertForbidden()
        ->assertJsonPath('message', trans('api.errors.stateful_frontend_required', locale: 'vi'))
        ->assertJsonPath('code', 'stateful_frontend_required');
});

it('localizes the public API error catalogue without changing stable error codes', function (): void {
    $response = $this->withHeader('Accept-Language', 'vi')
        ->getJson('/api/v1/meta/error-codes')
        ->assertOk();

    $badRequest = collect($response->json('data'))
        ->firstWhere('code', 'bad_request');

    expect($badRequest)
        ->toBeArray()
        ->and($badRequest['http_status'])->toBe(400)
        ->and($badRequest['description'])->toBe(trans('api.error_codes.bad_request', locale: 'vi'));
});

it('localizes API exceptions that happen before route middleware is available', function (): void {
    $this->withHeader('Accept-Language', 'vi')
        ->getJson('/api/v1/not-a-real-endpoint')
        ->assertNotFound()
        ->assertJsonPath('message', trans('api.errors.resource_not_found', locale: 'vi'))
        ->assertJsonPath('code', 'resource_not_found');
});

it('sends two-factor email copy in the recipients language and restores the worker locale', function (): void {
    $user = User::factory()->create();
    $user->profile->update(['locale' => 'vi']);

    app()->setLocale('en');

    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    $transport->flush();

    EmailTwoFactor::send(
        $user->uuid,
        $user->email,
        'localization_test',
        'localization_test_'.Str::uuid(),
    );

    $message = $transport->messages()->sole()->getOriginalMessage();

    expect($message->getSubject())->toBe(trans('notifications.two_factor.subject', locale: 'vi'))
        ->and($message->getTextBody())->toContain('Mã xác minh của bạn là:')
        ->and(app()->getLocale())->toBe('en');
});

it('preserves the users language when a queued account notification is delivered', function (): void {
    $user = User::factory()->create();
    $user->profile->update(['locale' => 'vi']);

    app()->setLocale('en');

    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    $transport->flush();
    $user->notify(new VerifyAccountEmail('opaque-test-token'));

    $message = $transport->messages()->sole()->getOriginalMessage();

    expect($message->getSubject())->toBe(trans('notifications.verify_email.subject', locale: 'vi'))
        ->and($message->getHtmlBody())->toContain(trans('notifications.verify_email.intro', locale: 'vi'))
        ->and(app()->getLocale())->toBe('en');
});

it('switches an OTP login to the pending users preferred language', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('panel.access', 'web');

    $user = User::factory()->create([
        'email' => 'localized-admin@example.com',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $user->profile->update(['locale' => 'vi']);
    $user->givePermissionTo('panel.access');

    app()->setLocale('en');
    session()->put('locale', 'en');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ], 'credentialsForm')
        ->call('submitCredentials')
        ->assertHasNoErrors()
        ->assertSet('step', 'otp')
        ->assertSee(trans('Use a recovery code', locale: 'vi'));

    expect(app()->getLocale())->toBe('vi')
        ->and(session('locale'))->toBe('vi');
});

it('uses the newly selected profile language for the success notification', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $user = User::factory()->create();
    $user->profile->update(['locale' => 'en']);

    $this->actingAs($user)
        ->withSession(['locale' => 'en']);
    app()->setLocale('en');

    Livewire::withHeaders(['Referer' => Profile::getUrl()])
        ->test(Profile::class)
        ->fillForm(['locale' => 'vi'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(trans('pages/account.saved', locale: 'vi'));

    expect(session('locale'))->toBe('vi')
        ->and($user->profile()->firstOrFail()->locale)->toBe('vi');
});

it('switches fallback-language users when the application default changes', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('settings.view', 'web');
    Permission::findOrCreate('settings.update', 'web');

    $user = User::factory()->create();
    $user->profile->update(['locale' => null]);
    $user->givePermissionTo(['settings.view', 'settings.update']);

    $this->actingAs($user)
        ->withSession(['locale' => 'en']);
    app()->setLocale('en');

    Livewire::test(GeneralSettings::class)
        ->fillForm(['locale' => 'vi'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(trans('pages/settings.saved', locale: 'vi'));

    expect(session('locale'))->toBe('vi')
        ->and(Settings::get('general', 'locale'))->toBe('vi');
});

it('localizes navigation labels, resource names, breadcrumbs, and unknown devices', function (): void {
    app()->setLocale('vi');

    expect(AccountCluster::getNavigationLabel())->toBe('Tài khoản')
        ->and(AccountCluster::getClusterBreadcrumb())->toBe('Tài khoản')
        ->and(SettingsCluster::getNavigationLabel())->toBe('Cài đặt')
        ->and(SettingsCluster::getClusterBreadcrumb())->toBe('Cài đặt')
        ->and(Profile::getNavigationLabel())->toBe('Hồ sơ')
        ->and(UserResource::getModelLabel())->toBe('người dùng')
        ->and(UserResource::getPluralModelLabel())->toBe('Người dùng')
        ->and(RoleResource::getModelLabel())->toBe('vai trò')
        ->and(LoginSessionResource::getModelLabel())->toBe('phiên đăng nhập')
        ->and(ActivityLogResource::getLabel())->toBe('Nhật ký hoạt động');

    $session = new LoginSession(['user_agent' => null]);

    expect($session->device_label)->toBe('Trình duyệt không xác định · Thiết bị không xác định');
});
