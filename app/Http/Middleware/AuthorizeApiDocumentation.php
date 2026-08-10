<?php

namespace App\Http\Middleware;

use Closure;
use Douwyn\StarterKit\Contracts\PanelAccessResolver;
use Douwyn\StarterKit\Platform;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeApiDocumentation
{
    public function __construct(
        private readonly PanelAccessResolver $panelAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->guest(Filament::getLoginUrl());
        }

        abort_unless(
            $this->panelAccess->canAccess(
                $user,
                Filament::getPanel(Platform::ADMIN_PANEL_ID),
            ),
            403,
        );

        return $next($request);
    }
}
