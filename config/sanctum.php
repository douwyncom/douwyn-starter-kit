<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    // Nuxt first-party requests authenticate with the web session cookie;
    // mobile and third-party clients fall back to Bearer tokens.
    'stateful' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:3000%s',
            Sanctum::currentApplicationUrlWithPort(),
        ))),
    ))),
    'guard' => ['web'],
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 43200),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'douwyn_'),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
