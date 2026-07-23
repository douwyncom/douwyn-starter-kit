<?php

use App\Models\TwoFactorCode;
use App\Models\User;
use App\Support\ActivityLogSanitizer;
use App\Support\EmailTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('supports laravel database sessions with uuid users', function () {
    config()->set('session.driver', 'database');
    app('session')->forgetDrivers();

    $this->get('/admin/login')->assertOk();

    $session = DB::table('sessions')->first();

    expect($session)->not->toBeNull()
        ->and($session->id)->toBeString();
});

it('never writes user credentials or two factor secrets to activity logs', function () {
    $user = User::factory()->create([
        'password' => 'Secret123',
        'two_factor_secret' => 'TOTP-SECRET',
        'two_factor_recovery_codes' => ['RECOVERY-SECRET'],
    ]);

    $user->forceFill([
        'password' => 'Changed123',
        'remember_token' => 'REMEMBER-SECRET',
        'two_factor_secret' => 'TOTP-CHANGED',
        'two_factor_recovery_codes' => ['RECOVERY-CHANGED'],
    ])->save();

    $properties = Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->getKey())
        ->get()
        ->pluck('properties')
        ->map(fn ($properties) => $properties->toArray())
        ->all();
    $encoded = json_encode($properties, JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('two_factor_secret')
        ->not->toContain('two_factor_recovery_codes')
        ->not->toContain('TOTP-SECRET')
        ->not->toContain('RECOVERY-SECRET');
});

it('redacts nested legacy activity properties defensively', function () {
    expect(ActivityLogSanitizer::sanitize([
        'attributes' => [
            'email' => 'member@example.com',
            'password' => 'hash',
            'setup_token' => 'douwyn_2fs_secret',
            'secret' => 'PENDING-TOTP-SECRET',
            'otpauth_uri' => 'otpauth://totp/private',
            'recovery_codes' => ['ONE-TIME-CODE'],
            'rotation_response' => 'encrypted-mobile-token-pair',
            'two_factor_secret' => 'secret',
            'refresh_token' => 'douwyn_rt_secret',
            'headers' => [
                'Authorization' => 'Bearer secret',
                'X-XSRF-TOKEN' => 'csrf-secret',
            ],
        ],
    ]))->toBe([
        'attributes' => [
            'email' => 'member@example.com',
            'password' => '[REDACTED]',
            'setup_token' => '[REDACTED]',
            'secret' => '[REDACTED]',
            'otpauth_uri' => '[REDACTED]',
            'recovery_codes' => '[REDACTED]',
            'rotation_response' => '[REDACTED]',
            'two_factor_secret' => '[REDACTED]',
            'refresh_token' => '[REDACTED]',
            'headers' => [
                'Authorization' => '[REDACTED]',
                'X-XSRF-TOKEN' => '[REDACTED]',
            ],
        ],
    ]);
});

it('rolls back an email otp when delivery fails', function () {
    $user = User::factory()->create();
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP unavailable'));

    expect(fn () => EmailTwoFactor::send($user->uuid, $user->email, 'login'))
        ->toThrow(ValidationException::class);

    expect(TwoFactorCode::query()->where('user_uuid', $user->uuid)->count())->toBe(0);
});

it('deletes two factor codes with their user', function () {
    $user = User::factory()->create();
    TwoFactorCode::query()->create([
        'user_uuid' => $user->uuid,
        'channel' => 'email',
        'purpose' => 'login',
        'code_hash' => 'hash',
        'expires_at' => now()->addMinute(),
    ]);

    $user->delete();

    expect(TwoFactorCode::query()->where('user_uuid', $user->uuid)->exists())->toBeFalse();
});
