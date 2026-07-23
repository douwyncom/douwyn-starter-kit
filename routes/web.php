<?php

use App\Http\Controllers\Api\AuthChallengeController;
use App\Http\Controllers\Api\SessionAuthController;
use App\Http\Middleware\EnsureStatefulFrontend;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\TrackLoginSession;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/v1/auth/session')
    ->name('api.session-auth.')
    ->middleware([EnsureStatefulFrontend::class, SetApiLocale::class])
    ->group(function (): void {
        Route::post('register', [SessionAuthController::class, 'register'])
            ->middleware('throttle:api-register')
            ->name('register');
        Route::post('login', [SessionAuthController::class, 'login'])
            ->middleware('throttle:api-login')
            ->name('login');
        Route::post('challenges/verify', [AuthChallengeController::class, 'verifySession'])
            ->middleware('throttle:api-login')
            ->name('challenge.verify');
        Route::post('logout', [SessionAuthController::class, 'logout'])
            ->middleware(['auth:web', TrackLoginSession::class])
            ->name('logout');
    });

Route::prefix('admin/api-docs')->name('api-docs.')->middleware(TrackLoginSession::class)->group(function (): void {
    Scramble::registerUiRoute('/')->name('ui');
    Scramble::registerJsonSpecificationRoute('openapi.json')->name('specification');
});
