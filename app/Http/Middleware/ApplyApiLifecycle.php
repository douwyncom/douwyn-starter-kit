<?php

namespace App\Http\Middleware;

use App\Support\ApiLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyApiLifecycle
{
    public function __construct(private readonly ApiLifecycle $lifecycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $this->lifecycle->begin($request);

        return $this->lifecycle->apply($next($request), $request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('api/*')) {
            $this->lifecycle->finish();
        }
    }
}
