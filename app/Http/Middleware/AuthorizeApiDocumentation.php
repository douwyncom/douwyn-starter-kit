<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeApiDocumentation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->guest(Filament::getLoginUrl());
        }

        abort_unless(
            ! $user->is_inactive
            && $user->hasAnyRole(['admin', 'super_admin'])
            && $user->hasPermissionTo('panel.access'),
            403,
        );

        return $next($request);
    }
}
