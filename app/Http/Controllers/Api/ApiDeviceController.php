<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenRevokeReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiDeviceSessionResource;
use App\Models\ApiDeviceSession;
use App\Services\Auth\MobileTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApiDeviceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = ApiDeviceSession::query()
            ->where('user_uuid', $request->user()->getAuthIdentifier())
            ->latest('last_seen_at')
            ->paginate(20);

        return ApiDeviceSessionResource::collection($sessions);
    }

    public function destroy(
        Request $request,
        string $deviceSession,
        MobileTokenService $tokens,
    ): Response {
        $session = ApiDeviceSession::query()
            ->where('user_uuid', $request->user()->getAuthIdentifier())
            ->whereKey($deviceSession)
            ->firstOrFail();

        $tokens->revokeDeviceSession($session, TokenRevokeReason::USER_REVOKED);

        return response()->noContent();
    }

    public function destroyAll(Request $request, MobileTokenService $tokens): Response
    {
        $tokens->revokeAll($request->user(), TokenRevokeReason::LOGOUT_ALL);

        return response()->noContent();
    }
}
