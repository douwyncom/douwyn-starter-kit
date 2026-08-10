<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Services\Auth\AuthSignature;
use App\Support\EmailTwoFactor;
use App\Support\TwoFactor;
use DateTimeImmutable;
use Douwyn\StarterKit\Contracts\SensitiveActionAuthorizer;
use Douwyn\StarterKit\Platform;
use Douwyn\StarterKit\Security\SensitiveActionAuthorization;
use Douwyn\StarterKit\Security\SensitiveActionContext;
use Douwyn\StarterKit\Security\SensitiveActionCredentials;
use Douwyn\StarterKit\Security\SensitiveActionFactor;
use Douwyn\StarterKit\Security\SensitiveActionRequirements;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;
use WeakMap;

final readonly class StarterKitSensitiveActionAuthorizer implements SensitiveActionAuthorizer
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 300;

    private const int MAX_AUTHORIZATION_TTL_SECONDS = 60;

    /**
     * @var WeakMap<SensitiveActionAuthorization, array{
     *     actor: string,
     *     context: string,
     *     request_binding: string,
     *     auth_signature: string,
     *     issued_at: float
     * }>
     */
    private WeakMap $issuedAuthorizations;

    public function __construct(
        private AuthSignature $authSignature,
        private SecurityTelemetry $telemetry,
    ) {
        $this->issuedAuthorizations = new WeakMap;
    }

    public function requirements(Authenticatable $user): SensitiveActionRequirements
    {
        $user = $this->authenticatedUser($user);
        $freshUser = User::query()->whereKey($user->getKey())->first();

        if (! $freshUser || $freshUser->is_inactive) {
            throw new AuthorizationException(__('Account is inactive.'));
        }

        $factor = $this->configuredFactor($freshUser);

        return new SensitiveActionRequirements(
            twoFactorRequired: $factor !== null,
            twoFactorMethod: $factor,
            recoveryCodeAccepted: $factor !== null
                && count((array) ($freshUser->two_factor_recovery_codes ?? [])) > 0,
        );
    }

    public function sendEmailChallenge(
        Authenticatable $user,
        SensitiveActionContext $context,
        #[\SensitiveParameter] string $currentPassword,
        ?Request $request = null,
    ): void {
        $request = $this->effectiveRequest($request);
        $user = $this->authenticatedUser($user, $request);
        $rateKey = $this->rateKey($user, 'email-password');

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->recordFailure($user, $request, $context, 'too_many_attempts');

            throw $this->rateLimitException($rateKey, 'current_password');
        }

        $result = DB::transaction(function () use ($user, $currentPassword): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->is_inactive) {
                return ['error' => 'account_inactive'];
            }

            if (! Hash::check($currentPassword, (string) $lockedUser->password)) {
                return ['error' => 'invalid_password'];
            }

            if (! $lockedUser->hasEnabledTwoFactor(TwoFactorMethod::EMAIL)) {
                return ['error' => 'email_factor_not_enabled'];
            }

            return ['user' => $lockedUser];
        });

        if (($result['error'] ?? null) === 'account_inactive') {
            $this->recordFailure($user, $request, $context, 'account_inactive');

            throw new AuthorizationException(__('Account is inactive.'));
        }

        if (($result['error'] ?? null) === 'invalid_password') {
            RateLimiter::hit($rateKey, self::DECAY_SECONDS);
            $this->recordFailure($user, $request, $context, 'invalid_password');

            throw ValidationException::withMessages([
                'current_password' => [__('pages/account.password.current_password_helper')],
            ]);
        }

        if (($result['error'] ?? null) === 'email_factor_not_enabled') {
            $this->recordFailure($user, $request, $context, 'email_factor_not_enabled');

            throw ValidationException::withMessages([
                'two_factor' => [__('Email two-factor authentication is not enabled.')],
            ]);
        }

        /** @var User $verifiedUser */
        $verifiedUser = $result['user'];
        RateLimiter::clear($rateKey);

        EmailTwoFactor::send(
            $verifiedUser->uuid,
            $verifiedUser->email,
            $this->emailPurpose($verifiedUser, $context, $request),
            'sensitive_action',
        );

        $this->telemetry->sensitiveActionChallengeIssued(
            $verifiedUser,
            $request,
            $context->action,
            $context->subject,
        );
    }

    public function authorize(
        Authenticatable $user,
        SensitiveActionContext $context,
        SensitiveActionCredentials $credentials,
        ?Request $request = null,
    ): SensitiveActionAuthorization {
        $request = $this->effectiveRequest($request);
        $user = $this->authenticatedUser($user, $request);
        $rateKey = $this->rateKey($user, 'authorize');

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->recordFailure($user, $request, $context, 'too_many_attempts');

            throw $this->rateLimitException($rateKey, 'otp');
        }

        $result = DB::transaction(function () use (
            $user,
            $context,
            $credentials,
            $request,
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->is_inactive) {
                return ['error' => 'account_inactive'];
            }

            if (! Hash::check($credentials->currentPassword(), (string) $lockedUser->password)) {
                return ['error' => 'invalid_password'];
            }

            $configuredFactor = $this->configuredFactor($lockedUser);

            if ($configuredFactor === null) {
                return ['user' => $lockedUser, 'factor' => null];
            }

            if ($credentials->oneTimePassword() === null
                && $credentials->recoveryCode() === null) {
                return ['error' => 'factor_required', 'factor' => $configuredFactor];
            }

            if ($credentials->recoveryCode() !== null) {
                $valid = TwoFactor::consumeRecoveryCodeForLockedUser(
                    $lockedUser,
                    $credentials->recoveryCode(),
                );
                $verifiedFactor = SensitiveActionFactor::RECOVERY_CODE;
            } else {
                $verifiedFactor = $configuredFactor;
                $valid = match ($configuredFactor) {
                    SensitiveActionFactor::AUTHENTICATOR => TwoFactor::verifyAndConsumeTotpForLockedUser(
                        $lockedUser,
                        (string) $credentials->oneTimePassword(),
                    ),
                    SensitiveActionFactor::EMAIL => EmailTwoFactor::verify(
                        $lockedUser->uuid,
                        (string) $credentials->oneTimePassword(),
                        $this->emailPurpose($lockedUser, $context, $request),
                    ),
                    SensitiveActionFactor::RECOVERY_CODE => false,
                };
            }

            if (! $valid) {
                return ['error' => 'invalid_factor', 'factor' => $verifiedFactor];
            }

            return ['user' => $lockedUser, 'factor' => $verifiedFactor];
        });

        $error = $result['error'] ?? null;

        if ($error === 'account_inactive') {
            $this->recordFailure($user, $request, $context, $error);

            throw new AuthorizationException(__('Account is inactive.'));
        }

        if ($error === 'invalid_password') {
            RateLimiter::hit($rateKey, self::DECAY_SECONDS);
            $this->recordFailure($user, $request, $context, $error);

            throw ValidationException::withMessages([
                'current_password' => [__('pages/account.password.current_password_helper')],
            ]);
        }

        if ($error === 'factor_required') {
            $this->recordFailure(
                $user,
                $request,
                $context,
                $error,
                $result['factor'] ?? null,
            );

            throw ValidationException::withMessages([
                'otp' => [__('The current two-factor code or a recovery code is required.')],
            ]);
        }

        if ($error === 'invalid_factor') {
            RateLimiter::hit($rateKey, self::DECAY_SECONDS);
            $this->recordFailure(
                $user,
                $request,
                $context,
                $error,
                $result['factor'] ?? null,
            );
            $field = $credentials->recoveryCode() !== null ? 'recovery_code' : 'otp';

            throw ValidationException::withMessages([
                $field => [__('Invalid or expired code.')],
            ]);
        }

        /** @var User $authorizedUser */
        $authorizedUser = $result['user'];
        /** @var SensitiveActionFactor|null $verifiedFactor */
        $verifiedFactor = $result['factor'];
        RateLimiter::clear($rateKey);

        $this->telemetry->sensitiveActionAuthorized(
            $authorizedUser,
            $request,
            $context->action,
            $context->subject,
            $verifiedFactor?->value,
        );

        $authorization = new SensitiveActionAuthorization(
            context: $context,
            verifiedFactor: $verifiedFactor,
            authorizedAt: new DateTimeImmutable,
        );

        $this->issuedAuthorizations[$authorization] = [
            'actor' => (string) $authorizedUser->getAuthIdentifier(),
            'context' => $context->canonical(),
            'request_binding' => $this->requestBinding($request),
            'auth_signature' => $this->authSignature->for($authorizedUser),
            'issued_at' => microtime(true),
        ];

        return $authorization;
    }

    public function consume(
        Authenticatable $user,
        SensitiveActionContext $context,
        SensitiveActionAuthorization $authorization,
        ?Request $request = null,
    ): void {
        $request = $this->effectiveRequest($request);
        $user = $this->authenticatedUser($user, $request);
        $issued = $this->issuedAuthorizations[$authorization] ?? null;

        // Consume before validating so a failed cross-context/session attempt
        // cannot be retried with the same capability.
        unset($this->issuedAuthorizations[$authorization]);

        if (! is_array($issued)) {
            throw $this->invalidAuthorization();
        }

        $freshUser = User::query()->whereKey($user->getKey())->first();
        $ttl = max(1, min(
            self::MAX_AUTHORIZATION_TTL_SECONDS,
            (int) config(
                'starter-kit.sensitive_action.authorization_ttl_seconds',
                self::MAX_AUTHORIZATION_TTL_SECONDS,
            ),
        ));

        if (! $freshUser
            || $freshUser->is_inactive
            || ! hash_equals(
                $issued['actor'],
                (string) $freshUser->getAuthIdentifier(),
            )
            || ! hash_equals($issued['context'], $context->canonical())
            || ! hash_equals(
                $issued['request_binding'],
                $this->requestBinding($request),
            )
            || ! hash_equals(
                $issued['auth_signature'],
                $this->authSignature->for($freshUser),
            )
            || microtime(true) - $issued['issued_at'] > $ttl) {
            throw $this->invalidAuthorization();
        }
    }

    private function authenticatedUser(
        Authenticatable $user,
        ?Request $request = null,
    ): User {
        if (! $user instanceof User) {
            throw new LogicException(sprintf(
                'The sensitive-action authorizer requires the configured starter-kit user model [%s].',
                User::class,
            ));
        }

        $authenticated = $request?->user() ?? Auth::guard(Platform::AUTH_GUARD)->user();

        if (! $authenticated instanceof Authenticatable
            || ! hash_equals(
                (string) $authenticated->getAuthIdentifier(),
                (string) $user->getAuthIdentifier(),
            )) {
            throw new AuthorizationException(__('Unauthenticated.'));
        }

        return $user;
    }

    private function configuredFactor(User $user): ?SensitiveActionFactor
    {
        if (! $user->hasEnabledTwoFactor()) {
            return null;
        }

        return match ($user->two_factor_method) {
            TwoFactorMethod::APP => SensitiveActionFactor::AUTHENTICATOR,
            TwoFactorMethod::EMAIL => SensitiveActionFactor::EMAIL,
            default => null,
        };
    }

    private function emailPurpose(
        User $user,
        SensitiveActionContext $context,
        Request $request,
    ): string {
        $payload = implode("\0", [
            $context->canonical(),
            $this->authSignature->for($user),
            $this->requestBinding($request),
        ]);

        return 'sensitive_action.'.hash_hmac(
            'sha256',
            $payload,
            (string) config('app.key'),
        );
    }

    private function requestBinding(Request $request): string
    {
        if ($request->hasSession()) {
            return 'session:'.hash('sha256', $request->session()->getId());
        }

        if (is_string($request->bearerToken()) && $request->bearerToken() !== '') {
            return 'bearer:'.hash('sha256', $request->bearerToken());
        }

        return 'request:'.hash('sha256', (string) spl_object_id($request));
    }

    private function effectiveRequest(?Request $request): Request
    {
        return $request ?? request();
    }

    private function rateKey(User $user, string $operation): string
    {
        $userHash = hash_hmac(
            'sha256',
            (string) $user->getAuthIdentifier(),
            (string) config('app.key'),
        );

        return "starter-kit:sensitive-action:{$operation}:{$userHash}";
    }

    private function rateLimitException(string $rateKey, string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => [__('Too many verification attempts. Please try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($rateKey),
            ])],
        ]);
    }

    private function invalidAuthorization(): AuthorizationException
    {
        return new AuthorizationException(
            __('Sensitive-action authorization is invalid or expired.'),
        );
    }

    private function recordFailure(
        User $user,
        Request $request,
        SensitiveActionContext $context,
        string $reason,
        ?SensitiveActionFactor $factor = null,
    ): void {
        $this->telemetry->sensitiveActionAuthorizationFailed(
            $user,
            $request,
            $context->action,
            $context->subject,
            $reason,
            $factor?->value,
        );
    }
}
