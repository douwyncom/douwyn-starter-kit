<?php

use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

it('registers an application user and returns a sanctum token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'New.User@Example.com',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'first_name' => 'New',
        'last_name' => 'User',
        'device_name' => 'iPhone',
        'locale' => 'vi',
        'timezone' => 'Asia/Ho_Chi_Minh',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'new.user@example.com')
        ->assertJsonPath('data.user.profile.first_name', 'New')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();

    expect($user->roles)->toBeEmpty()
        ->and($user->tokens)->toHaveCount(1)
        ->and($user->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});

it('logs in and accesses protected profile endpoints with a bearer token', function () {
    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'Secret123',
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'MEMBER@example.com',
        'password' => 'Secret123',
        'device_name' => 'Android',
    ])->assertOk();

    $token = $login->json('data.token');

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.uuid', $user->uuid);

    $this->withToken($token)
        ->patchJson('/api/v1/auth/me', ['first_name' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.profile.first_name', 'Updated');
});

it('rejects invalid credentials and inactive accounts', function () {
    $user = User::factory()->create(['password' => 'Secret123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Browser',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $user->update(['is_inactive' => true]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Browser',
    ])->assertForbidden()->assertJsonPath('code', 'account_inactive');
});

it('enforces two factor authentication and accepts a recovery code', function () {
    Mail::fake();

    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
        'two_factor_recovery_codes' => ['RECOVERY-123456'],
    ]);

    $credentials = [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'iPad',
    ];

    $challenge = $this->postJson('/api/v1/auth/login', $credentials)
        ->assertStatus(202)
        ->assertJsonPath('code', 'two_factor_required')
        ->assertJsonPath('data.method', 'email')
        ->json('data.challenge_token');

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $challenge,
        'recovery_code' => 'recovery123456',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);

    expect($user->fresh()->two_factor_recovery_codes)->toBeEmpty();
});

it('lists and revokes api tokens including logout', function () {
    $user = User::factory()->create();
    $firstToken = $user->createToken('Phone')->plainTextToken;
    $secondToken = $user->createToken('Laptop')->plainTextToken;

    $tokens = $this->withToken($firstToken)
        ->getJson('/api/v1/auth/tokens')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $secondTokenId = collect($tokens->json('data'))->firstWhere('name', 'Laptop')['id'];

    $this->withToken($firstToken)
        ->deleteJson("/api/v1/auth/tokens/$secondTokenId")
        ->assertOk();

    expect(PersonalAccessToken::findToken($secondToken))->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($firstToken)->postJson('/api/v1/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($firstToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('immediately revokes existing tokens when an account becomes inactive', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Phone')->plainTextToken;
    $user->update(['is_inactive' => true]);

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0);
});

it('enforces token abilities', function () {
    $user = User::factory()->create();
    $updateOnlyToken = $user->createToken('Limited', ['user:update'])->plainTextToken;

    $this->withToken($updateOnlyToken)
        ->getJson('/api/v1/auth/me')
        ->assertForbidden();
});

it('changes the password and revokes other api and browser sessions', function () {
    $user = User::factory()->create(['password' => 'Secret123']);
    $currentToken = $user->createToken('Current', ['user:read', 'user:update'])->plainTextToken;
    $otherToken = $user->createToken('Other')->plainTextToken;

    $this->withToken($currentToken)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Secret123',
            'password' => 'Changed123',
            'password_confirmation' => 'Changed123',
        ])
        ->assertOk();

    expect($user->fresh()->tokens)->toHaveCount(1)
        ->and(PersonalAccessToken::findToken($otherToken))->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($currentToken)->getJson('/api/v1/auth/me')->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Changed123',
        'device_name' => 'New device',
    ])->assertOk();
});

it('allows only admin roles into filament', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect($staff->canAccessPanel(filament()->getPanel('admin')))->toBeFalse()
        ->and($admin->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();
});

it('rejects a case insensitive duplicate email as validation instead of a server error', function () {
    User::factory()->create(['email' => 'member@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'email' => ' MEMBER@EXAMPLE.COM ',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'first_name' => 'Duplicate',
        'last_name' => 'Member',
        'device_name' => 'Browser',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects passwords beyond the bcrypt byte limit', function () {
    $password = str_repeat('a', 72).'1';

    $this->postJson('/api/v1/auth/register', [
        'email' => 'long-password@example.com',
        'password' => $password,
        'password_confirmation' => $password,
        'first_name' => 'Long',
        'last_name' => 'Password',
        'device_name' => 'Browser',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('rejects reusing the same authenticator code', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $otp = TwoFactor::google2fa()->getCurrentOtp($secret);
    $credentials = [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Browser',
    ];

    $firstChallenge = $this->postJson('/api/v1/auth/login', $credentials)
        ->assertStatus(202)
        ->json('data.challenge_token');

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $firstChallenge,
        'otp' => $otp,
    ])->assertOk();

    $secondChallenge = $this->postJson('/api/v1/auth/login', $credentials)
        ->assertStatus(202)
        ->json('data.challenge_token');

    $this->postJson('/api/v1/auth/token/challenges/verify', [
        'challenge_token' => $secondChallenge,
        'otp' => $otp,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('otp');
});

it('rehashes an outdated password after successful credential verification', function () {
    $user = User::factory()->create();
    DB::table('users')->where('uuid', $user->uuid)->update([
        'password' => password_hash('Secret123', PASSWORD_BCRYPT, ['cost' => 5]),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Secret123',
        'device_name' => 'Browser',
    ])->assertOk();

    expect(password_get_info($user->fresh()->password)['options']['cost'])->toBe(4);
});

it('deletes sanctum tokens when their user is deleted', function () {
    $user = User::factory()->create();
    $plainTextToken = $user->createToken('Browser')->plainTextToken;

    $user->delete();

    expect(PersonalAccessToken::findToken($plainTextToken))->toBeNull();
});

it('always renders api authentication errors as json', function () {
    $this->get('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
});

it('localizes public api errors from the accept language header', function () {
    $this->withHeader('Accept-Language', 'vi')
        ->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'Secret123',
            'device_name' => 'Browser',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Thông tin đăng nhập không chính xác.');
});

it('allows only configured nuxt origins through cors', function () {
    $headers = [
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ];

    $this->withHeaders($headers)
        ->options('/api/v1/auth/login')
        ->assertNoContent()
        ->assertHeader('access-control-allow-origin', 'http://localhost:3000')
        ->assertHeader('access-control-allow-credentials', 'true');

    $this->withHeaders([...$headers, 'Origin' => 'https://attacker.example'])
        ->options('/api/v1/auth/login')
        ->assertNoContent()
        ->assertHeaderMissing('access-control-allow-origin');
});

it('rate limits protected api traffic by bearer token', function () {
    RateLimiter::for('api', fn (): Limit => Limit::perMinute(2)->by('test-token'));

    $user = User::factory()->create();
    $token = $user->createToken('Browser')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertTooManyRequests();
});
