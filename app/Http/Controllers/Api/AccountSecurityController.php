<?php

namespace App\Http\Controllers\Api;

use App\Data\Auth\IssuedTwoFactorSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\AccountSecurityConfirmRequest;
use App\Http\Requests\Api\Auth\AccountSecurityMutationRequest;
use App\Http\Requests\Api\Auth\AccountSecurityPasswordRequest;
use App\Http\Requests\Api\Auth\AccountSecurityResendRequest;
use App\Services\Auth\TwoFactorSetupService;
use Carbon\CarbonInterface;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSecurityController extends Controller
{
    private const array SENSITIVE_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    private const string TWO_FACTOR_STATUS_SCHEMA = 'array{enabled: bool, method: \'none\'|\'app\'|\'email\', confirmed_at: \\Carbon\\CarbonInterface|null, enabled_at: \\Carbon\\CarbonInterface|null, recovery_codes_remaining: int, pending_setup: array{method: \'app\'|\'email\', expires_at: \\Carbon\\CarbonInterface}|null}';

    private const string STATUS_RESPONSE_SCHEMA = 'array{data: array{two_factor: '.self::TWO_FACTOR_STATUS_SCHEMA.'}}';

    private const string APP_SETUP_RESPONSE_SCHEMA = 'array{message: string, data: array{setup_token: string, method: \'app\', secret: string, otpauth_uri: string, expires_at: \\Carbon\\CarbonInterface}}';

    private const string EMAIL_SETUP_RESPONSE_SCHEMA = 'array{message: string, data: array{setup_token: string, method: \'email\', expires_at: \\Carbon\\CarbonInterface}}';

    private const string RECOVERY_CODES_RESPONSE_SCHEMA = 'array{message: string, data: array{two_factor: '.self::TWO_FACTOR_STATUS_SCHEMA.', recovery_codes: list<string>}}';

    private const string DISABLE_RESPONSE_SCHEMA = 'array{message: string, data: array{two_factor: '.self::TWO_FACTOR_STATUS_SCHEMA.'}}';

    private const string MESSAGE_RESPONSE_SCHEMA = 'array{message: string}';

    private const string FORBIDDEN_RESPONSE_SCHEMA = 'array{message: string}';

    /** Get the authenticated user's two-factor security status. */
    #[ScrambleResponse(status: 200, type: self::STATUS_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function status(Request $request, TwoFactorSetupService $twoFactor): JsonResponse
    {
        return response()->json([
            'data' => [
                'two_factor' => $twoFactor->status($request->user()),
            ],
        ]);
    }

    /** Start an authenticator-app setup without replacing the active factor yet. */
    #[ScrambleResponse(status: 201, type: self::APP_SETUP_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function setupApp(
        AccountSecurityMutationRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $setup = $twoFactor->startApp(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('otp'),
            $request->validated('recovery_code'),
        );

        return response()->json([
            'message' => __('auth.messages.authenticator_setup_started'),
            'data' => $this->serializeSetup($setup),
        ], 201, self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Confirm an authenticator-app setup and return new recovery codes once. */
    #[ScrambleResponse(status: 200, type: self::RECOVERY_CODES_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function confirmApp(
        AccountSecurityConfirmRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $result = $twoFactor->confirmApp(
            $request->user(),
            $request->validated('setup_token'),
            (string) $request->validated('otp'),
            $request,
        );

        return response()->json([
            'message' => __('auth.messages.authenticator_enabled'),
            'data' => $result,
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Start an email two-factor setup and send its confirmation code. */
    #[ScrambleResponse(status: 201, type: self::EMAIL_SETUP_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function setupEmail(
        AccountSecurityMutationRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $setup = $twoFactor->startEmail(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('otp'),
            $request->validated('recovery_code'),
        );

        return response()->json([
            'message' => __('auth.messages.email_code_sent'),
            'data' => $this->serializeSetup($setup),
        ], 201, self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Resend the email code for an existing pending setup. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function resendEmail(
        AccountSecurityResendRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $twoFactor->resendEmail(
            $request->user(),
            $request->validated('setup_token'),
        );

        return response()->json([
            'message' => __('auth.messages.email_code_sent'),
        ]);
    }

    /** Confirm an email setup and return new recovery codes once. */
    #[ScrambleResponse(status: 200, type: self::RECOVERY_CODES_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function confirmEmail(
        AccountSecurityConfirmRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $result = $twoFactor->confirmEmail(
            $request->user(),
            $request->validated('setup_token'),
            (string) $request->validated('otp'),
            $request,
        );

        return response()->json([
            'message' => __('auth.messages.email_two_factor_enabled'),
            'data' => $result,
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /** Send a current-factor email code for a sensitive security mutation. */
    #[ScrambleResponse(status: 200, type: self::MESSAGE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function sendCurrentEmailCode(
        AccountSecurityPasswordRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $twoFactor->sendCurrentEmailCode(
            $request->user(),
            $request->validated('current_password'),
        );

        return response()->json([
            'message' => __('auth.messages.email_code_sent'),
        ]);
    }

    /** Disable two-factor authentication after password and current-factor verification. */
    #[ScrambleResponse(status: 200, type: self::DISABLE_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function disable(
        AccountSecurityMutationRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $status = $twoFactor->disable(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('otp'),
            $request->validated('recovery_code'),
            $request,
        );

        return response()->json([
            'message' => __('auth.messages.two_factor_disabled'),
            'data' => ['two_factor' => $status],
        ]);
    }

    /** Replace recovery codes and return their plaintext values once. */
    #[ScrambleResponse(status: 200, type: self::RECOVERY_CODES_RESPONSE_SCHEMA)]
    #[ScrambleResponse(status: 403, description: 'The credential lacks the required ability.', type: self::FORBIDDEN_RESPONSE_SCHEMA)]
    public function regenerateRecoveryCodes(
        AccountSecurityMutationRequest $request,
        TwoFactorSetupService $twoFactor,
    ): JsonResponse {
        $result = $twoFactor->regenerateRecoveryCodes(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('otp'),
            $request->validated('recovery_code'),
            $request,
        );

        return response()->json([
            'message' => __('auth.messages.recovery_codes_regenerated'),
            'data' => $result,
        ], headers: self::SENSITIVE_RESPONSE_HEADERS);
    }

    /**
     * @return array{
     *     setup_token: string,
     *     method: 'app'|'email',
     *     secret?: string,
     *     otpauth_uri?: string,
     *     expires_at: CarbonInterface
     * }
     */
    private function serializeSetup(IssuedTwoFactorSetup $setup): array
    {
        return array_filter([
            'setup_token' => $setup->plainTextToken,
            'method' => $setup->setup->method->value,
            'secret' => $setup->secret,
            'otpauth_uri' => $setup->otpauthUri,
            'expires_at' => $setup->setup->expires_at,
        ], fn ($value): bool => $value !== null);
    }
}
