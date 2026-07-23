<?php

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Enums\SecurityEvent;
use App\Enums\TokenRevokeReason;
use App\Enums\TwoFactorMethod;
use App\Filament\Clusters\Account\Pages\Password;
use App\Filament\Widgets\RecentSecurityEvents;
use App\Filament\Widgets\SecurityEventStats;
use App\Models\LoginSession;
use App\Models\User;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use App\Services\Security\SecurityTelemetry;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

it('records mobile login outcomes without persisting raw credentials or device identifiers', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $deviceId = (string) Str::uuid();
    $userAgent = 'MobileClient access_token=raw-access-secret otp=654321';
    $payload = [
        'email' => $user->email,
        'device_name' => 'Telemetry phone',
        'device_id' => $deviceId,
        'platform' => 'ios',
    ];

    $failedResponse = $this->withHeader('User-Agent', $userAgent)
        ->postJson('/api/v1/auth/token/login', [
            ...$payload,
            'password' => 'wrong-password',
        ])
        ->assertUnprocessable();

    $login = $this->withHeader('User-Agent', $userAgent)
        ->postJson('/api/v1/auth/token/login', [
            ...$payload,
            'password' => 'Secret123',
        ])
        ->assertOk();

    $failed = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->where('event', SecurityEvent::LOGIN_FAILED->value)
        ->sole();
    $succeeded = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->where('event', SecurityEvent::LOGIN_SUCCEEDED->value)
        ->sole();
    $serialized = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->get()
        ->map(fn (Activity $activity): string => $activity->properties->toJson())
        ->implode('\n');

    expect($failed->getExtraProperty('identity_hash'))->toHaveLength(64)
        ->and($failed->getExtraProperty('channel'))->toBe('mobile')
        ->and($failed->getExtraProperty('request_id'))
        ->toBe($failedResponse->headers->get('X-Request-ID'))
        ->and($succeeded->getExtraProperty('credential_type'))->toBe('token')
        ->and($serialized)->not->toContain($user->email)
        ->and($serialized)->not->toContain('wrong-password')
        ->and($serialized)->not->toContain('Secret123')
        ->and($serialized)->not->toContain($deviceId)
        ->and($serialized)->not->toContain($userAgent)
        ->and($serialized)->not->toContain((string) $login->json('data.access_token'))
        ->and($serialized)->not->toContain((string) $login->json('data.refresh_token'))
        ->and($serialized)->not->toContain((string) $login->json('data.device_session_id'));
});

it('records two factor challenges and failures without persisting the challenge or otp', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $deviceId = (string) Str::uuid();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);

    $challengeToken = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => '2FA phone',
        'device_id' => $deviceId,
        'platform' => 'android',
    ])->assertStatus(202)->json('data.challenge_token');

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $challengeToken,
        'device_id' => $deviceId,
        'otp' => '000000',
    ])->assertUnprocessable();

    $events = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->pluck('event');
    $serialized = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->get()
        ->map(fn (Activity $activity): string => $activity->properties->toJson())
        ->implode('\n');

    expect($events)->toContain(
        SecurityEvent::TWO_FACTOR_CHALLENGE_ISSUED->value,
        SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED->value,
    )
        ->and($serialized)->not->toContain($challengeToken)
        ->and($serialized)->not->toContain('000000')
        ->and($serialized)->not->toContain($deviceId)
        ->and($serialized)->not->toContain($secret);
});

it('records refresh reuse and session revocation without exposing replay credentials', function () {
    $deviceId = (string) Str::uuid();
    $user = User::factory()->create(['password' => 'Secret123']);

    $login = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Reuse test',
        'device_id' => $deviceId,
        'platform' => 'ios',
    ])->assertOk();
    $refreshToken = (string) $login->json('data.refresh_token');
    $deviceSessionId = (string) $login->json('data.device_session_id');

    $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $refreshToken,
        'device_id' => $deviceId,
    ])->assertOk();

    $this->postJson('/api/v1/auth/token/refresh', [
        'request_id' => (string) Str::uuid(),
        'refresh_token' => $refreshToken,
        'device_id' => $deviceId,
    ])->assertUnauthorized();

    $rawSessionId = 'raw-browser-session-credential';
    $browserSession = LoginSession::query()->create([
        'id' => $rawSessionId,
        'user_uuid' => $user->uuid,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Telemetry browser',
        'last_active_at' => now(),
    ]);
    $browserSession->revoke();

    $events = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->pluck('event');
    $serialized = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->get()
        ->map(fn (Activity $activity): string => $activity->properties->toJson())
        ->implode('\n');

    expect($events)->toContain(
        SecurityEvent::REFRESH_TOKEN_REUSED->value,
        SecurityEvent::DEVICE_SESSION_REVOKED->value,
        SecurityEvent::BROWSER_SESSION_REVOKED->value,
    )
        ->and($serialized)->not->toContain($refreshToken)
        ->and($serialized)->not->toContain($deviceId)
        ->and($serialized)->not->toContain($deviceSessionId)
        ->and($serialized)->not->toContain($rawSessionId);
});

it('shows seven day security widgets only to activity log viewers', function () {
    $subject = User::factory()->create();
    app(SecurityTelemetry::class)->loginFailed(
        $subject,
        null,
        'mobile',
        'invalid_credentials',
        $subject->email,
    );
    $recentActivity = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->sole();

    activity(SecurityTelemetry::LOG_NAME)
        ->event(SecurityEvent::LOGIN_FAILED->value)
        ->performedOn($subject)
        ->createdAt(now()->subDays(8))
        ->withProperties(['channel' => 'mobile', 'reason' => 'invalid_credentials'])
        ->log('security.login_failed');
    $oldActivity = Activity::query()->latest('id')->firstOrFail();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('activity_logs.view');
    $this->actingAs($viewer);

    Livewire::test(SecurityEventStats::class)
        ->assertSee(__('dashboard.security_events.heading'))
        ->assertSee(__('dashboard.security_events.failed_logins'));
    Livewire::test(RecentSecurityEvents::class)
        ->assertCanSeeTableRecords([$recentActivity])
        ->assertCanNotSeeTableRecords([$oldActivity]);

    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized);

    expect(SecurityEventStats::canView())->toBeFalse()
        ->and(RecentSecurityEvents::canView())->toBeFalse();
});

it('counts every revoked session instead of only telemetry rows', function () {
    $user = User::factory()->create();

    app(SecurityTelemetry::class)->browserSessionsRevoked(
        $user,
        null,
        'all_sessions',
        revokedCount: 4,
    );
    activity(SecurityTelemetry::LOG_NAME)
        ->event(SecurityEvent::DEVICE_SESSION_REVOKED->value)
        ->performedOn($user)
        ->withProperties(['channel' => 'mobile'])
        ->log('security.device_session_revoked');

    $widget = app(SecurityEventStats::class);
    $method = (new ReflectionClass($widget))->getMethod('getStats');
    $stats = $method->invoke($widget);

    expect($stats[3]->getValue())->toBe(5);
});

it('records password changes through the authenticated api flow', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    Sanctum::actingAs($user, ['user:update']);

    $this->putJson('/api/v1/auth/password', [
        'current_password' => 'Secret123',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertOk();

    $activity = Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->where('event', SecurityEvent::PASSWORD_CHANGED->value)
        ->sole();

    expect($activity->subject_id)->toBe($user->uuid)
        ->and($activity->properties->toJson())->not->toContain('Secret123')
        ->and($activity->properties->toJson())->not->toContain('NewSecret123');
});

it('revokes browser and mobile access when a filament administrator changes password', function () {
    $admin = User::factory()->create(['password' => 'Secret123']);
    $admin->assignRole('super_admin');
    $deviceId = (string) Str::uuid();
    $pair = app(MobileTokenService::class)->issue($admin, new MobileDeviceData(
        deviceName: 'Admin phone',
        deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
        platform: DevicePlatform::IOS,
        appVersion: '1.0.0',
        ipAddress: '203.0.113.20',
        userAgent: 'AdminMobile/1.0',
    ));
    $otherBrowser = LoginSession::query()->create([
        'id' => 'other-admin-browser',
        'user_uuid' => $admin->uuid,
        'ip_address' => '203.0.113.21',
        'user_agent' => 'Other browser',
        'last_active_at' => now(),
    ]);

    $this->actingAs($admin);

    Livewire::test(Password::class)
        ->set('data.current_password', 'Secret123')
        ->set('data.password', 'NewSecret123')
        ->set('data.password_confirmation', 'NewSecret123')
        ->call('save')
        ->assertHasNoErrors();

    expect($pair->deviceSession->fresh()->revoke_reason)->toBe(TokenRevokeReason::PASSWORD_CHANGED)
        ->and($otherBrowser->fresh()->revoked_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::PASSWORD_CHANGED->value)
            ->where('subject_id', $admin->uuid)
            ->exists())->toBeTrue();
});
