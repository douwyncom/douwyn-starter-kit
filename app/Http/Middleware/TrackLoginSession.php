<?php

namespace App\Http\Middleware;

use App\Models\LoginSession;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class TrackLoginSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();
        $registryId = (string) $request->session()->get('login_session_registry_id', $sessionId);
        $loginSession = LoginSession::query()->find($registryId);

        if ($loginSession?->revoked_at !== null || ($loginSession && $loginSession->user_uuid !== $user->uuid)) {
            $user->invalidateRememberedLogin();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->is('api/*')) {
                return new JsonResponse([
                    'message' => __('api.errors.session_revoked'),
                    'code' => 'session_revoked',
                ], 401);
            }

            return redirect()->to(Filament::getLoginUrl());
        }

        if (! hash_equals($registryId, $sessionId)) {
            $loginSession?->revoke('session_regenerated');
            $loginSession = null;
            $request->session()->put('login_session_registry_id', $sessionId);
        } elseif (! $request->session()->has('login_session_registry_id')) {
            $request->session()->put('login_session_registry_id', $sessionId);
        }

        if (! $loginSession || $loginSession->last_active_at->lt(now()->subMinute())) {
            LoginSession::query()->updateOrCreate(
                ['id' => $sessionId],
                [
                    'user_uuid' => $user->uuid,
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                    'last_active_at' => now(),
                    'revoked_at' => null,
                ],
            );
        }

        $response = $next($request);

        if (! Auth::guard('web')->check()) {
            LoginSession::query()->whereKey($request->session()->get('login_session_registry_id', $sessionId))->update(['revoked_at' => now()]);
        }

        return $response;
    }
}
