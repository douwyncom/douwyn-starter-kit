<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns validation errors for structured values in string authentication fields', function (string $endpoint, string $field): void {
    config(['sanctum.stateful' => ['localhost']]);

    if ($endpoint === '/api/v1/account/email/change') {
        Sanctum::actingAs(User::factory()->create(), ['user:update']);
    }

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'Accept-Language' => 'vi',
    ])
        ->postJson($endpoint, [$field => ['unexpected' => 'value']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors([$field]);
})->with([
    'mobile login email' => ['/api/v1/auth/token/login', 'email'],
    'mobile login password' => ['/api/v1/auth/token/login', 'password'],
    'mobile registration email' => ['/api/v1/auth/token/register', 'email'],
    'mobile registration password' => ['/api/v1/auth/token/register', 'password'],
    'session login email' => ['/api/v1/auth/session/login', 'email'],
    'session login password' => ['/api/v1/auth/session/login', 'password'],
    'session registration email' => ['/api/v1/auth/session/register', 'email'],
    'session registration password' => ['/api/v1/auth/session/register', 'password'],
    'forgot password email' => ['/api/v1/auth/password/forgot', 'email'],
    'two-factor challenge token' => ['/api/v1/auth/token/challenges/verify', 'challenge_token'],
    'mobile refresh token' => ['/api/v1/auth/token/refresh', 'refresh_token'],
    'email verification token' => ['/api/v1/auth/email/verify', 'token'],
    'password reset token' => ['/api/v1/auth/password/reset', 'token'],
    'password reset password' => ['/api/v1/auth/password/reset', 'password'],
    'email change email' => ['/api/v1/account/email/change', 'email'],
]);
