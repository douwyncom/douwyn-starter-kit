<?php

use App\Enums\AccountActionType;
use App\Enums\SecurityEvent;
use App\Enums\TwoFactorMethod;
use App\Models\AccountActionToken;
use App\Models\ApiDeviceSession;
use App\Models\LoginSession;
use App\Models\User;
use App\Notifications\ConfirmAccountEmailChange;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccountEmail;
use App\Services\Security\SecurityTelemetry;
use App\Support\TwoFactor;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

function lifecycleBearer(User $user, string $name = 'Lifecycle test'): string
{
    return $user->createToken($name, ['user:read', 'user:update'])->plainTextToken;
}

function lifecycleStatefulHeaders(): array
{
    return [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/account',
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

function capturedUserNotificationToken(User $user, string $notificationClass): string
{
    $notification = Notification::sent($user, $notificationClass)->last();

    expect($notification)->not->toBeNull();

    return $notification->token;
}

it('issues and consumes an opaque email-verification token with a bearer credential', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $bearer = lifecycleBearer($user);

    $this->withToken($bearer)
        ->postJson('/api/v1/account/email/verification-notification')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache');

    $plainTextToken = capturedUserNotificationToken($user, VerifyAccountEmail::class);
    $stored = AccountActionToken::query()
        ->where('type', AccountActionType::EMAIL_VERIFICATION)
        ->latest()
        ->firstOrFail();

    expect($plainTextToken)->toStartWith('douwyn_ev_')
        ->and($stored->token_hash)->not->toBe($plainTextToken)
        ->and($stored->token_hash)->toBe(AccountActionToken::tokenHash(
            $plainTextToken,
            AccountActionType::EMAIL_VERIFICATION,
        ))
        ->and((string) DB::table('account_action_tokens')->value('target_email'))->not->toBe($user->email);

    $this->postJson('/api/v1/auth/email/verify', ['token' => $plainTextToken])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('message', 'Email address verified.')
        ->assertJsonMissingPath('data');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($stored->fresh()->consumed_at)->not->toBeNull();

    $this->postJson('/api/v1/auth/email/verify', ['token' => $plainTextToken])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('supports email verification from a Nuxt stateful session', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['password' => 'Secret123']);
    $headers = lifecycleStatefulHeaders();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Account',
        ])
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson('/api/v1/account/email/verification-notification')
        ->assertOk();

    $token = capturedUserNotificationToken($user, VerifyAccountEmail::class);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/email/verify', ['token' => $token])
        ->assertOk();

    $this->withHeaders($headers)
        ->getJson('/api/v1/account/email')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.pending_email', null);

    $this->assertAuthenticatedAs($user, 'web');
});

it('does not enumerate accounts when requesting a password reset', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'member@example.com']);
    $inactive = User::factory()->create([
        'email' => 'inactive@example.com',
        'is_inactive' => true,
    ]);

    $existing = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => ' MEMBER@EXAMPLE.COM ',
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $missing = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'missing@example.com',
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $inactiveResponse = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'inactive@example.com',
    ])->assertOk();

    expect($existing->json())->toBe($missing->json())
        ->and($existing->json())->toBe($inactiveResponse->json());

    Notification::assertSentTo($user, ResetAccountPassword::class);
    Notification::assertNotSentTo($inactive, ResetAccountPassword::class);
    expect(AccountActionToken::query()->where('type', AccountActionType::PASSWORD_RESET)->count())->toBe(1);
});

it('keeps password reset enumeration safe when notification delivery fails', function () {
    $user = User::factory()->create(['email' => 'delivery-failure@example.com']);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('Notification transport unavailable.'));
    $this->app->instance(Dispatcher::class, $dispatcher);

    $existing = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => $user->email,
    ])->assertOk();
    $missing = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => 'missing-delivery-failure@example.com',
    ])->assertOk();

    expect($existing->json())->toBe($missing->json());
});

it('rate limits password reset requests by normalized email', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $email = $attempt % 2 === 0 ? 'LIMIT@EXAMPLE.COM' : 'limit@example.com';
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertOk();
    }

    $this->postJson('/api/v1/auth/password/forgot', ['email' => ' Limit@Example.com '])
        ->assertTooManyRequests();
});

it('resets a password once and revokes every API and browser credential', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'reset@example.com',
        'password' => 'Secret123',
    ]);
    $firstToken = lifecycleBearer($user, 'First');
    $secondToken = lifecycleBearer($user, 'Second');
    $unrelatedUser = User::factory()->create();
    LoginSession::query()->create([
        'id' => Str::random(40),
        'user_uuid' => $user->uuid,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Nuxt test',
        'last_active_at' => now(),
    ]);

    $this->actingAs($unrelatedUser, 'web');
    $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
    $resetToken = capturedUserNotificationToken($user, ResetAccountPassword::class);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $resetToken,
        'password' => 'Changed456',
        'password_confirmation' => 'Changed456',
    ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('message', 'Password reset successfully. Please sign in again.');

    expect(Hash::check('Changed456', $user->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::findToken($firstToken))->toBeNull()
        ->and(PersonalAccessToken::findToken($secondToken))->toBeNull()
        ->and(LoginSession::query()->where('user_uuid', $user->uuid)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::PASSWORD_CHANGED->value)
            ->where('subject_id', $user->uuid)
            ->where('properties->channel', 'password_reset')
            ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('subject_id', $user->uuid)
            ->whereNotNull('causer_id')
            ->exists())->toBeFalse();

    $this->assertAuthenticatedAs($unrelatedUser, 'web');

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $resetToken,
        'password' => 'Another789',
        'password_confirmation' => 'Another789',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('logs out a current stateful session after a public password reset', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => 'Secret123']);
    $headers = lifecycleStatefulHeaders();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Reset',
        ])
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
        ->assertOk();

    $resetToken = capturedUserNotificationToken($user, ResetAccountPassword::class);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/password/reset', [
            'token' => $resetToken,
            'password' => 'Changed456',
            'password_confirmation' => 'Changed456',
        ])
        ->assertOk();

    $this->assertGuest('web');
    $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('requires a verified account before starting an email change', function () {
    Notification::fake();
    $unverified = User::factory()->unverified()->create(['password' => 'Secret123']);

    $this->withToken(lifecycleBearer($unverified))
        ->postJson('/api/v1/account/email/change', [
            'email' => 'new@example.com',
            'current_password' => 'Secret123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Notification::assertNothingSent();
});

it('requires the current password and current 2fa for email change', function () {
    Notification::fake();

    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'email_verified_at' => now(),
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes(['RECOVERY-123456']),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $bearer = lifecycleBearer($user);

    $this->withToken($bearer)
        ->postJson('/api/v1/account/email/change', [
            'email' => 'new@example.com',
            'current_password' => 'Wrong123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->withToken($bearer)
        ->postJson('/api/v1/account/email/change', [
            'email' => 'new@example.com',
            'current_password' => 'Secret123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp');

    $this->withToken($bearer)
        ->postJson('/api/v1/account/email/change', [
            'email' => ' NEW@EXAMPLE.COM ',
            'current_password' => 'Secret123',
            'otp' => TwoFactor::google2fa()->getCurrentOtp($secret),
        ])
        ->assertStatus(202)
        ->assertHeader('Cache-Control', 'no-store, private');

    Notification::assertSentOnDemand(ConfirmAccountEmailChange::class);
});

it('changes a mobile users email and preserves only the confirming device family', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'mobile@example.com',
        'password' => 'Secret123',
    ]);

    $first = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'iPhone',
        'device_id' => (string) Str::uuid(),
        'platform' => 'ios',
    ])->assertOk();
    $second = $this->postJson('/api/v1/auth/token/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Android',
        'device_id' => (string) Str::uuid(),
        'platform' => 'android',
    ])->assertOk();
    $firstAccess = $first->json('data.access_token');
    $secondAccess = $second->json('data.access_token');

    $this->withToken($firstAccess)
        ->postJson('/api/v1/account/email/change', [
            'email' => ' Changed.Mobile@Example.COM ',
            'current_password' => 'Secret123',
        ])
        ->assertStatus(202);

    $changeToken = null;
    Notification::assertSentOnDemand(
        ConfirmAccountEmailChange::class,
        function (ConfirmAccountEmailChange $notification, array $channels, object $notifiable) use (&$changeToken): bool {
            $changeToken = $notification->token;

            return in_array('mail', $channels, true)
                && data_get($notifiable, 'routes.mail') === 'changed.mobile@example.com'
                && $notification->locale === 'vi';
        },
    );

    expect($changeToken)->toBeString()->toStartWith('douwyn_ec_')
        ->and((string) DB::table('account_action_tokens')
            ->where('type', AccountActionType::EMAIL_CHANGE->value)
            ->value('target_email'))
        ->not->toBe('changed.mobile@example.com');

    $this->withToken($firstAccess)
        ->getJson('/api/v1/account/email')
        ->assertOk()
        ->assertJsonPath('data.pending_email', 'changed.mobile@example.com');

    $this->withToken($firstAccess)
        ->postJson('/api/v1/account/email/change/confirm', ['token' => $changeToken])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.email', 'changed.mobile@example.com');

    expect($user->fresh()->email)->toBe('changed.mobile@example.com')
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(PersonalAccessToken::findToken($firstAccess))->not->toBeNull()
        ->and(PersonalAccessToken::findToken($secondAccess))->toBeNull()
        ->and(ApiDeviceSession::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(Activity::query()
            ->where('log_name', SecurityTelemetry::LOG_NAME)
            ->where('event', SecurityEvent::SECURITY_CHANGED->value)
            ->where('subject_id', $user->uuid)
            ->where('properties->change', 'email_changed')
            ->where('properties->channel', 'mobile')
            ->exists())->toBeTrue();

    $this->withToken($firstAccess)
        ->postJson('/api/v1/account/email/change/confirm', ['token' => $changeToken])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('enforces case-insensitive email uniqueness before issuing a change token', function () {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'Secret123',
    ]);

    $this->withToken(lifecycleBearer($user))
        ->postJson('/api/v1/account/email/change', [
            'email' => ' TAKEN@EXAMPLE.COM ',
            'current_password' => 'Secret123',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Notification::assertNothingSent();
    expect(AccountActionToken::query()->where('type', AccountActionType::EMAIL_CHANGE)->exists())->toBeFalse();
});

it('preserves the current Nuxt session after confirming an email change', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'nuxt@example.com',
        'password' => 'Secret123',
    ]);
    $headers = lifecycleStatefulHeaders();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/session/login', [
            'email' => $user->email,
            'password' => 'Secret123',
            'device_name' => 'Nuxt Email Change',
        ])
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson('/api/v1/account/email/change', [
            'email' => 'nuxt.changed@example.com',
            'current_password' => 'Secret123',
        ])
        ->assertStatus(202);

    $token = null;
    Notification::assertSentOnDemand(
        ConfirmAccountEmailChange::class,
        function (ConfirmAccountEmailChange $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        },
    );

    $this->withHeaders($headers)
        ->postJson('/api/v1/account/email/change/confirm', ['token' => $token])
        ->assertOk();

    $this->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'nuxt.changed@example.com');

    $this->assertAuthenticatedAs($user, 'web');

    expect(Activity::query()
        ->where('log_name', SecurityTelemetry::LOG_NAME)
        ->where('event', SecurityEvent::SECURITY_CHANGED->value)
        ->where('subject_id', $user->uuid)
        ->where('properties->change', 'email_changed')
        ->where('properties->channel', 'nuxt_session')
        ->exists())->toBeTrue();
});
