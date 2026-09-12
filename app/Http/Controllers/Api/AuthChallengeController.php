<?php

namespace App\Http\Controllers\Api;

use App\Data\Auth\MobileDeviceData;
use App\Enums\AuthCredentialType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ChallengeVerifyRequest;
use App\Http\Resources\Api\MobileTokenPairResource;
use App\Http\Resources\Api\UserResource;
use App\Services\Auth\AuthChallengeService;
use App\Services\Auth\MobileTokenService;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\TokenIssuer;
use App\Services\Security\SecurityTelemetry;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Illuminate\Http\JsonResponse;

class AuthChallengeController extends Controller
{
    private const array SENSITIVE_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    public function verifyToken(
        ChallengeVerifyRequest $request,
        AuthChallengeService $challenges,
        TokenIssuer $tokens,
        MobileTokenService $mobileTokens,
        SecurityTelemetry $telemetry,
        TokenAbilityRegistry $abilities,
    ): JsonResponse {
        $challenge = $challenges->verify(
            $request->validated('challenge_token'),
            AuthCredentialType::TOKEN,
            $request,
            $request->validated('otp'),
            $request->validated('recovery_code'),
        );

        if (filled($challenge->device_id_hash)) {
            $pair = $mobileTokens->issue(
                $challenge->user,
                MobileDeviceData::fromChallenge($challenge),
                $challenge->abilities ?: $abilities->abilitiesFor(TokenAbilityProfile::MOBILE),
            );
            $telemetry->loginSucceeded(
                $challenge->user,
                $request,
                'mobile',
                AuthCredentialType::TOKEN,
                true,
            );

            return MobileTokenPairResource::make($pair)
                ->additional(['message' => __('auth.messages.logged_in')])
                ->response();
        }

        $data = $tokens->issue(
            $challenge->user,
            $challenge->device_name ?: 'Mobile',
            $request,
            $challenge->abilities ?: $abilities->abilitiesFor(TokenAbilityProfile::LEGACY),
        );
        $telemetry->loginSucceeded(
            $challenge->user,
            $request,
            'legacy_token',
            AuthCredentialType::TOKEN,
            true,
        );

        return response()->json([
            'message' => __('auth.messages.logged_in'),
            'data' => [
                ...$data,
                'user' => UserResource::make($data['user']),
            ],
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    public function verifySession(
        ChallengeVerifyRequest $request,
        AuthChallengeService $challenges,
        SessionIssuer $sessions,
        SecurityTelemetry $telemetry,
    ): JsonResponse {
        $challenge = $challenges->verify(
            $request->validated('challenge_token'),
            AuthCredentialType::SESSION,
            $request,
            $request->validated('otp'),
            $request->validated('recovery_code'),
        );

        $sessions->login($challenge->user, $request);
        $telemetry->loginSucceeded(
            $challenge->user,
            $request,
            'nuxt_session',
            AuthCredentialType::SESSION,
            true,
        );

        return response()->json([
            'message' => __('auth.messages.logged_in'),
            'data' => [
                'credential_type' => AuthCredentialType::SESSION->value,
                'user' => UserResource::make($challenge->user->load('profile')),
            ],
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }
}
