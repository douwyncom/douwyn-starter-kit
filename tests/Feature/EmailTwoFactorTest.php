<?php

use App\Models\TwoFactorCode;
use App\Models\User;
use App\Support\EmailTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

it('invalidates only earlier codes for the same purpose', function () {
    Mail::fake();

    $user = User::factory()->create();
    $loginCode = TwoFactorCode::create([
        'user_uuid' => $user->uuid,
        'channel' => 'email',
        'purpose' => 'login',
        'code_hash' => Hash::make('111111'),
        'expires_at' => now()->addMinutes(5),
    ]);
    $setupCode = TwoFactorCode::create([
        'user_uuid' => $user->uuid,
        'channel' => 'email',
        'purpose' => 'email_two_factor',
        'code_hash' => Hash::make('222222'),
        'expires_at' => now()->addMinutes(5),
    ]);
    $loginCode->forceFill(['created_at' => now()->subMinute()])->saveQuietly();

    RateLimiter::clear("2fa:send:email:login:{$user->uuid}");
    EmailTwoFactor::send($user->uuid, $user->email, 'login');

    expect($loginCode->fresh()->consumed_at)->not->toBeNull()
        ->and($setupCode->fresh()->consumed_at)->toBeNull();
});

it('consumes a valid email code only once', function () {
    $user = User::factory()->create();
    TwoFactorCode::create([
        'user_uuid' => $user->uuid,
        'channel' => 'email',
        'purpose' => 'login',
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
    ]);

    expect(EmailTwoFactor::verify($user->uuid, '123456', 'login'))->toBeTrue()
        ->and(EmailTwoFactor::verify($user->uuid, '123456', 'login'))->toBeFalse();
});
