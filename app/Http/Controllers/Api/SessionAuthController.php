<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuthCredentialType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\SessionLoginRequest;
use App\Http\Resources\Api\UserResource;
use App\Services\Auth\AuthChallengeService;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\UserRegistrationService;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionAuthController extends Controller
{
    private const array AUTH_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    public function register(
        RegisterRequest $request,
        UserRegistrationService $registration,
        SessionIssuer $sessions,
    ): JsonResponse {
        $user = $registration->register($request->validated());
        $sessions->login($user, $request);

        return response()->json([
            'message' => __('Account created successfully.'),
            'data' => [
                'credential_type' => AuthCredentialType::SESSION->value,
                'user' => UserResource::make($user->load('profile')),
            ],
        ], 201, self::AUTH_RESPONSE_HEADERS);
    }

    public function login(
        SessionLoginRequest $request,
        CredentialAuthenticator $credentials,
        AuthChallengeService $challenges,
        SessionIssuer $sessions,
        SecurityTelemetry $telemetry,
    ): JsonResponse {
        $data = $request->validated();
        $user = $credentials->authenticate($data['email'], $data['password'], $request, 'nuxt_session');

        if ($user->hasEnabledTwoFactor()) {
            $issuedChallenge = $challenges->issue(
                $user,
                AuthCredentialType::SESSION,
                $request,
                $data['device_name'] ?? 'Nuxt Web',
            );

            return response()->json([
                'message' => __('Two-factor authentication is required.'),
                'code' => 'two_factor_required',
                'data' => [
                    'challenge_token' => $issuedChallenge->plainTextToken,
                    'method' => $user->two_factor_method->value,
                    'recovery_available' => ! empty($user->two_factor_recovery_codes),
                    'expires_at' => $issuedChallenge->challenge->expires_at,
                ],
            ], 202, self::AUTH_RESPONSE_HEADERS);
        }

        $sessions->login($user, $request);
        $telemetry->loginSucceeded($user, $request, 'nuxt_session', AuthCredentialType::SESSION);

        return response()->json([
            'message' => __('Logged in successfully.'),
            'data' => [
                'credential_type' => AuthCredentialType::SESSION->value,
                'user' => UserResource::make($user->load('profile')),
            ],
        ], headers: self::AUTH_RESPONSE_HEADERS);
    }

    public function logout(Request $request, SessionIssuer $sessions): JsonResponse
    {
        $sessions->logout($request);

        return response()->json(['message' => __('Logged out successfully.')]);
    }
}
