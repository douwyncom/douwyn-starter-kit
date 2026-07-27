<?php

use App\Http\Middleware\ApplyApiLifecycle;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\TrackLoginSession;
use App\Support\ApiExceptionResponse;
use Douwyn\StarterKit\Platform;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApplyApiLifecycle::class);
        $middleware->trustHosts(subdomains: false);
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : '/admin/login',
        );

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            SetApiLocale::class,
        );
        $middleware->group(Platform::API_LOCALIZED_MIDDLEWARE, [
            SetApiLocale::class,
        ]);
        $middleware->group(Platform::API_AUTHENTICATED_MIDDLEWARE, [
            SetApiLocale::class,
            'auth:sanctum',
            EnsureUserIsActive::class,
            TrackLoginSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(
            fn ($response, Throwable $exception, Request $request) => app(ApiExceptionResponse::class)
                ->prepare($response, $exception, $request),
        );
    })->create();
