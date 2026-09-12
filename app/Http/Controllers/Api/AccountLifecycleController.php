<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\AccountActionTokenRequest;
use App\Http\Requests\Api\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Auth\RequestEmailChangeRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\UserResource;
use App\Services\Auth\AccountLifecycleService;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountLifecycleController extends Controller
{
    private const array SENSITIVE_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    private const string MESSAGE_RESPONSE_SCHEMA = 'array{message: string}';

    private const string ERROR_RESPONSE_SCHEMA = 'array{message: string, code: App\Enums\ApiErrorCode}';

    private const string VALIDATION_RESPONSE_SCHEMA = 'array{message: string, errors: array<string, list<string>>, code: App\Enums\ApiErrorCode}';

    private const string EMAIL_STATUS_RESPONSE_SCHEMA = 'array{data: array{email: string, verified: bool, verified_at: \Carbon\CarbonInterface|null, pending_email: string|null, pending_expires_at: \Carbon\CarbonInterface|null}}';

    private const string USER_RESPONSE_SCHEMA = 'array{message: string, data: App\Http\Resources\Api\UserResource}';

    /** Get email verification and pending-change status for the current account. */
    #[ScrambleResponse(status: 200, type: self::EMAIL_STATUS_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 401, type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function emailStatus(Request $request, AccountLifecycleService $lifecycle): JsonResponse
    {
        return response()->json([
            'data' => $lifecycle->emailStatus($request->user()),
        ]);
    }

    /** Send a new opaque verification link to the current account email. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 401, type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many verification emails.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function resendEmailVerification(
        Request $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $lifecycle->sendEmailVerification($request->user());

        return response()->json([
            'message' => __('api.account.email_verification_sent'),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Verify an account email using the opaque token delivered by email. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 422, type: self::VALIDATION_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many token attempts.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function verifyEmail(
        AccountActionTokenRequest $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $lifecycle->verifyEmail($request->validated('token'));

        return response()->json([
            'message' => __('api.account.email_verified'),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Request a password-reset link without revealing account existence. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 422, type: self::VALIDATION_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many password-reset requests.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function forgotPassword(
        ForgotPasswordRequest $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $lifecycle->sendPasswordReset($request->validated('email'));

        return response()->json([
            'message' => __('api.account.password_reset_sent'),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Reset a password and revoke every browser and API credential. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 422, type: self::VALIDATION_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many token attempts.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function resetPassword(
        ResetPasswordRequest $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $lifecycle->resetPassword(
            $request->validated('token'),
            $request->validated('password'),
            $request,
        );

        return response()->json([
            'message' => __('api.account.password_reset_completed'),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Start a verified email change after password and current-factor step-up. */
    #[ScrambleResponse(status: 202, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 401, type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 422, type: self::VALIDATION_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many email-change requests.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function requestEmailChange(
        RequestEmailChangeRequest $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $lifecycle->requestEmailChange(
            $request->user(),
            $request->validated('email'),
            $request->validated('current_password'),
            $request->validated('otp'),
            $request->validated('recovery_code'),
        );

        return response()->json([
            'message' => __('api.account.email_change_confirmation_sent'),
        ], 202, self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Confirm the pending email change and revoke other account access. */
    #[ScrambleResponse(status: 200, type: self::USER_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 401, type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::ERROR_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 422, type: self::VALIDATION_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 429, description: 'Too many token attempts.', type: self::ERROR_RESPONSE_SCHEMA)]
    public function confirmEmailChange(
        AccountActionTokenRequest $request,
        AccountLifecycleService $lifecycle,
    ): JsonResponse {
        $user = $lifecycle->confirmEmailChange(
            $request->user(),
            $request->validated('token'),
            $request,
        );

        return response()->json([
            'message' => __('api.account.email_changed'),
            'data' => UserResource::make($user->load('profile')),
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }
}
