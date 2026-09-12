<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\MobileLogoutRequest;
use App\Http\Requests\Api\Auth\MobileRefreshRequest;
use App\Http\Resources\Api\MobileTokenPairResource;
use App\Services\Auth\MobileTokenService;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Symfony\Component\HttpFoundation\Response;

class MobileTokenController extends Controller
{
    #[ScrambleResponse(status: 401, description: 'The refresh token is invalid, expired, or reused.', type: "array{message: string, code: 'reauthentication_required'}")]
    #[ScrambleResponse(status: 403, description: 'The account is inactive.', type: "array{message: string, code: 'account_inactive'}")]
    #[ScrambleResponse(status: 409, description: 'The same refresh request is still being processed.', type: "array{message: string, code: 'refresh_in_progress'}")]
    #[ScrambleResponse(status: 429, description: 'Refresh rate limit exceeded.', type: 'array{message: string}')]
    public function refresh(
        MobileRefreshRequest $request,
        MobileTokenService $tokens,
    ): MobileTokenPairResource {
        $tokenPair = $tokens->rotate(
            $request->validated('refresh_token'),
            $request->validated('device_id'),
            $request,
        );

        return MobileTokenPairResource::make($tokenPair)
            ->additional(['message' => __('auth.messages.access_refreshed')]);
    }

    public function logout(
        MobileLogoutRequest $request,
        MobileTokenService $tokens,
    ): Response {
        $tokens->logout(
            $request->validated('refresh_token'),
            $request->validated('device_id'),
        );

        return response()->noContent();
    }
}
