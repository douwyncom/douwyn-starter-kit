<?php

use App\Http\Controllers\Api\AccountLifecycleController;
use App\Http\Controllers\Api\AccountSecurityController;
use App\Http\Controllers\Api\ApiDeviceController;
use App\Http\Controllers\Api\ApiMetadataController;
use App\Http\Controllers\Api\AuthChallengeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LoginSessionController;
use App\Http\Controllers\Api\MobileTokenController;
use App\Http\Controllers\Api\TokenAuthController;
use App\Http\Middleware\SetApiLocale;
use Douwyn\StarterKit\Platform;
use Illuminate\Support\Facades\Route;

Route::get('v1/meta/error-codes', [ApiMetadataController::class, 'errorCodes'])
    ->middleware(SetApiLocale::class);

Route::prefix('v1/auth')->middleware(SetApiLocale::class)->group(function (): void {
    Route::post('email/verify', [AccountLifecycleController::class, 'verifyEmail'])
        ->middleware('throttle:api-account-token');
    Route::post('password/forgot', [AccountLifecycleController::class, 'forgotPassword'])
        ->middleware('throttle:api-password-forgot');
    Route::post('password/reset', [AccountLifecycleController::class, 'resetPassword'])
        ->middleware('throttle:api-account-token');
    Route::post('token/register', [TokenAuthController::class, 'registerMobile'])->middleware('throttle:api-register');
    Route::post('token/login', [TokenAuthController::class, 'loginMobile'])->middleware('throttle:api-login');
    Route::post('token/challenges/verify', [AuthChallengeController::class, 'verifyToken'])
        ->middleware('throttle:api-login');
    Route::post('token/refresh', [MobileTokenController::class, 'refresh'])->middleware('throttle:api-refresh');
    Route::post('token/logout', [MobileTokenController::class, 'logout'])->middleware('throttle:api-refresh');

    if (config('auth_tokens.legacy_endpoints')) {
        // Explicit opt-in migration bridge for pre-rotation clients.
        Route::post('register', [TokenAuthController::class, 'register'])->middleware('throttle:api-register');
        Route::post('login', [TokenAuthController::class, 'login'])->middleware('throttle:api-login');
    }

    Route::middleware(Platform::API_AUTHENTICATED_MIDDLEWARE)->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->middleware('abilities:user:read');
        Route::patch('me', [AuthController::class, 'updateProfile'])->middleware('abilities:user:update');
        Route::put('password', [AuthController::class, 'changePassword'])->middleware('abilities:user:update');
        Route::get('tokens', [AuthController::class, 'tokens'])->middleware('abilities:user:read');
        Route::delete('tokens/{token}', [AuthController::class, 'revokeToken'])
            ->middleware('abilities:user:update')
            ->whereNumber('token');
        Route::post('token/logout-all', [TokenAuthController::class, 'logoutAllMobile']);
        Route::post('logout', [TokenAuthController::class, 'logout']);
        Route::post('logout-all', [TokenAuthController::class, 'logoutAll']);
        Route::get('devices', [ApiDeviceController::class, 'index'])->middleware('abilities:devices:read');
        Route::delete('devices', [ApiDeviceController::class, 'destroyAll'])->middleware('abilities:devices:revoke');
        Route::delete('devices/{deviceSession}', [ApiDeviceController::class, 'destroy'])
            ->middleware('abilities:devices:revoke');
    });
});

Route::prefix('v1/account/email')
    ->middleware(Platform::API_AUTHENTICATED_MIDDLEWARE)
    ->group(function (): void {
        Route::get('/', [AccountLifecycleController::class, 'emailStatus'])->middleware('abilities:user:read');

        Route::middleware('abilities:user:update')->group(function (): void {
            Route::post('verification-notification', [AccountLifecycleController::class, 'resendEmailVerification'])
                ->middleware('throttle:api-account-email');
            Route::post('change', [AccountLifecycleController::class, 'requestEmailChange'])
                ->middleware('throttle:api-email-change');
            Route::post('change/confirm', [AccountLifecycleController::class, 'confirmEmailChange'])
                ->middleware('throttle:api-account-token');
        });
    });

Route::prefix('v1/account/security')
    ->middleware(Platform::API_AUTHENTICATED_MIDDLEWARE)
    ->group(function (): void {
        Route::get('/', [AccountSecurityController::class, 'status'])->middleware('abilities:user:read');
        Route::get('sessions', [LoginSessionController::class, 'index'])->middleware('abilities:user:read');

        Route::middleware('abilities:user:update')->group(function (): void {
            Route::delete('sessions/others', [LoginSessionController::class, 'destroyOthers']);
            Route::delete('sessions', [LoginSessionController::class, 'destroyAll']);
            Route::delete('sessions/{session}', [LoginSessionController::class, 'destroy']);
            Route::post('two-factor/app/setup', [AccountSecurityController::class, 'setupApp']);
            Route::post('two-factor/app/confirm', [AccountSecurityController::class, 'confirmApp']);
            Route::post('two-factor/email/setup', [AccountSecurityController::class, 'setupEmail']);
            Route::post('two-factor/email/resend', [AccountSecurityController::class, 'resendEmail']);
            Route::post('two-factor/email/confirm', [AccountSecurityController::class, 'confirmEmail']);
            Route::post('two-factor/email/current-code', [AccountSecurityController::class, 'sendCurrentEmailCode']);
            Route::delete('two-factor', [AccountSecurityController::class, 'disable']);
            Route::post('recovery-codes/regenerate', [AccountSecurityController::class, 'regenerateRecoveryCodes']);
        });
    });
