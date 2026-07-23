<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\LoginSessionResource;
use App\Models\User;
use App\Services\Auth\LoginSessionIdentifier;
use App\Services\Auth\SessionIssuer;
use App\Services\Security\SecurityTelemetry;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class LoginSessionController extends Controller
{
    private const string LIST_RESPONSE_SCHEMA = 'array{data: list<\\App\\Http\\Resources\\Api\\LoginSessionResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}, context: array{credential_type: \'token\'|\'session\'}}';

    private const string REVOKE_RESPONSE_SCHEMA = 'array{message: string, data: array{id: string, current_session_revoked: bool}}';

    private const string REVOKE_MANY_RESPONSE_SCHEMA = 'array{message: string, data: array{revoked_count: int, current_session_revoked: bool}}';

    private const string FORBIDDEN_RESPONSE_SCHEMA = 'array{message: string}';

    /** List active browser sessions owned by the authenticated user. */
    #[ScrambleResponse(status: 200, type: self::LIST_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $currentSessionId = $this->currentSessionId($request, $user);
        $request->attributes->set(LoginSessionResource::CURRENT_SESSION_ATTRIBUTE, $currentSessionId);

        $sessions = $user->loginSessions()
            ->active()
            ->latest('last_active_at')
            ->paginate(20);

        return LoginSessionResource::collection($sessions)->additional([
            'context' => [
                'credential_type' => $currentSessionId === null ? 'token' : 'session',
            ],
        ]);
    }

    /** Revoke one browser session using its opaque public identifier. */
    #[ScrambleResponse(status: 200, type: self::REVOKE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function destroy(
        Request $request,
        string $session,
        LoginSessionIdentifier $identifiers,
        SessionIssuer $sessions,
    ): JsonResponse {
        $user = $this->user($request);
        $loginSession = $identifiers->resolveForUser($user, $session);

        abort_if($loginSession === null, 404);

        $currentSessionId = $this->currentSessionId($request, $user);
        $isCurrent = $currentSessionId !== null
            && hash_equals($currentSessionId, (string) $loginSession->getKey());

        $loginSession->revoke();

        if ($isCurrent) {
            $sessions->logout($request);
        }

        return response()->json([
            'message' => $isCurrent
                ? __('The current browser session has been revoked.')
                : __('Browser session revoked.'),
            'data' => [
                'id' => $session,
                'current_session_revoked' => $isCurrent,
            ],
        ]);
    }

    /** Revoke every browser session except the current stateful session. */
    #[ScrambleResponse(status: 200, type: self::REVOKE_MANY_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function destroyOthers(Request $request, SecurityTelemetry $telemetry): JsonResponse
    {
        $user = $this->user($request);
        $currentSessionId = $this->currentSessionId($request, $user);
        $query = $user->loginSessions()->notRevoked();

        if ($currentSessionId !== null) {
            $query->whereKeyNot($currentSessionId);
        }

        $revokedCount = $query->update(['revoked_at' => now()]);

        if ($revokedCount > 0) {
            $user->invalidateRememberedLogin();
            $telemetry->browserSessionsRevoked(
                $user,
                $request,
                'other_sessions',
                $revokedCount,
            );
        }

        return response()->json([
            'message' => $currentSessionId === null
                ? __('All browser sessions have been revoked. Bearer access remains active.')
                : __('Other browser sessions have been revoked.'),
            'data' => [
                'revoked_count' => $revokedCount,
                'current_session_revoked' => false,
            ],
        ]);
    }

    /** Revoke every browser session; bearer credentials remain independent. */
    #[ScrambleResponse(status: 200, type: self::REVOKE_MANY_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function destroyAll(
        Request $request,
        SessionIssuer $sessions,
        SecurityTelemetry $telemetry,
    ): JsonResponse {
        $user = $this->user($request);
        $currentSessionId = $this->currentSessionId($request, $user);
        $revokedCount = $user->loginSessions()
            ->notRevoked()
            ->update(['revoked_at' => now()]);

        if ($revokedCount > 0) {
            $user->invalidateRememberedLogin();
            $telemetry->browserSessionsRevoked(
                $user,
                $request,
                'all_sessions',
                $revokedCount,
            );
        }

        if ($currentSessionId !== null) {
            $sessions->logout($request);
        }

        return response()->json([
            'message' => $currentSessionId === null
                ? __('All browser sessions have been revoked. Bearer access remains active.')
                : __('All browser sessions, including the current session, have been revoked.'),
            'data' => [
                'revoked_count' => $revokedCount,
                'current_session_revoked' => $currentSessionId !== null,
            ],
        ]);
    }

    private function currentSessionId(Request $request, User $user): ?string
    {
        if ($user->currentAccessToken() instanceof PersonalAccessToken
            || ! $request->hasSession()
            || ! Auth::guard('web')->check()
            || Auth::guard('web')->id() !== $user->getAuthIdentifier()) {
            return null;
        }

        return (string) $request->session()->get(
            'login_session_registry_id',
            $request->session()->getId(),
        );
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
