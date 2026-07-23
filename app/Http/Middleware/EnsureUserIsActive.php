<?php

namespace App\Http\Middleware;

use App\Enums\TokenRevokeReason;
use App\Services\Auth\AccessRevocationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function __construct(private readonly AccessRevocationService $revocation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_inactive) {
            $this->revocation->revokeAll($user, TokenRevokeReason::ACCOUNT_INACTIVE);

            if ($request->hasSession() && Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return new JsonResponse([
                'message' => __('Account is inactive.'),
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
