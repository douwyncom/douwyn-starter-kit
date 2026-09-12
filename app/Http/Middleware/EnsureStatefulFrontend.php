<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

class EnsureStatefulFrontend
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            return new JsonResponse([
                'message' => __('api.errors.stateful_frontend_required'),
                'code' => 'stateful_frontend_required',
            ], 403);
        }

        return $next($request);
    }
}
