<?php

use App\Http\Middleware\AuthorizeApiDocumentation;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api/v1',
    'api_domain' => null,
    'export_path' => 'api.json',
    'cache' => [
        'key' => 'scramble.openapi.v1',
        'store' => 'file',
    ],
    'info' => [
        'version' => trim((string) env('API_VERSION', '1.0.0')) ?: '1.0.0',
        'description' => <<<'MARKDOWN'
            # Douwyn Starter Kit API

            Authentication is powered by Laravel Sanctum in two modes: first-party Nuxt applications use secure session cookies with CSRF protection, while mobile clients use short-lived Bearer access tokens and rotating refresh tokens.

            Accounts with two-factor authentication enabled must complete an email OTP, authenticator OTP, or recovery-code challenge before a session or token is issued.

            Every API response includes `X-Request-ID` and `X-API-Version`. Optional RFC-compliant deprecation and sunset headers are documented on each operation. The machine-readable error catalogue is available at `/meta/error-codes`.
            MARKDOWN,
    ],
    'ui' => [
        'title' => 'Douwyn API Documentation',
    ],
    'middleware' => [
        AuthorizeApiDocumentation::class,
    ],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
