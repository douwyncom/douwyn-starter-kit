<?php

namespace App\Http\Middleware;

use Closure;
use Douwyn\StarterKit\Contracts\LocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->locales->resolveForApi($request));

        return $next($request);
    }
}
