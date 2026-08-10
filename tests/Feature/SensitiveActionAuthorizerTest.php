<?php

declare(strict_types=1);

use App\Enums\SecurityEvent;
use App\Enums\TwoFactorMethod;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Support\ActivityLogSanitizer;
use App\Support\TwoFactor;
use Douwyn\StarterKit\Contracts\SensitiveActionAuthorizer;
use Douwyn\StarterKit\Security\SensitiveActionAuthorization;
use Douwyn\StarterKit\Security\SensitiveActionContext;
use Douwyn\StarterKit\Security\SensitiveActionCredentials;
use Douwyn\StarterKit\Security\SensitiveActionFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('unused');
    Mail::fake();
});

it('binds the public authorizer and permits password-only step-up without 2FA', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $context = new SensitiveActionContext('infrastructure.secret.reveal', 'secret-uuid');
    $authorizer = app(SensitiveActionAuthorizer::class);

    $requirements = $authorizer->requirements($user);
    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    );

    expect($requirements->twoFactorRequired)->toBeFalse()
        ->and($requirements->twoFactorMethod)->toBeNull()
        ->and($authorization->context)->toBe($context)
        ->and($authorization->verifiedFactor)->toBeNull()
        ->and($authorization->usedRecoveryCode())->toBeFalse();

    $properties = json_decode(
        (string) DB::table('activity_log')
            ->where('event', SecurityEvent::SENSITIVE_ACTION_AUTHORIZED->value)
            ->value('properties'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($properties)->toMatchArray([
        'action' => 'infrastructure.secret.reveal',
        'verified_factor' => 'password',
    ])->and($properties)->toHaveKey('subject_hash')
        ->and($properties)->not->toHaveKey('current_password')
        ->and(ActivityLogSanitizer::sanitize([
            'one_time_password' => '123456',
        ]))->toBe([
            'one_time_password' => '[REDACTED]',
        ]);

    $authorizer->consume($user, $context, $authorization);
});

it('verifies authenticator OTPs and prevents replay for sensitive actions', function () {
    $secret = TwoFactor::google2fa()->generateSecretKey();
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ])->save();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext('infrastructure.secret.reveal', 'secret-uuid');
    $otp = TwoFactor::google2fa()->getCurrentOtp($secret);

    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password', $otp),
    );

    expect($authorization->verifiedFactor)->toBe(SensitiveActionFactor::AUTHENTICATOR);

    expect(fn () => $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password', $otp),
    ))->toThrow(ValidationException::class);
});

it('consumes a recovery code as the alternative current second factor', function () {
    $recoveryCode = 'ABCDE-12345-FGHIJ-67890';
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_method' => TwoFactorMethod::APP,
        'two_factor_secret' => TwoFactor::google2fa()->generateSecretKey(),
        'two_factor_recovery_codes' => TwoFactor::hashRecoveryCodes([$recoveryCode]),
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ])->save();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);

    $requirements = $authorizer->requirements($user);
    $authorization = $authorizer->authorize(
        $user,
        new SensitiveActionContext('infrastructure.secret.reveal', 'secret-uuid'),
        new SensitiveActionCredentials('password', recoveryCode: $recoveryCode),
    );

    expect($requirements->recoveryCodeAccepted)->toBeTrue()
        ->and($authorization->verifiedFactor)->toBe(SensitiveActionFactor::RECOVERY_CODE)
        ->and($authorization->usedRecoveryCode())->toBeTrue()
        ->and($user->refresh()->two_factor_recovery_codes)->toBe([]);
});

it('sends and consumes a context-bound email challenge', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ])->save();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext('infrastructure.secret.reveal', 'secret-uuid');

    expect($authorizer->requirements($user)->emailChallengeRequired())->toBeTrue();

    $authorizer->sendEmailChallenge($user, $context, 'password');

    $code = TwoFactorCode::query()
        ->where('user_uuid', $user->uuid)
        ->where('purpose', 'like', 'sensitive_action.%')
        ->latest()
        ->firstOrFail();
    $code->forceFill(['code_hash' => Hash::make('123456')])->save();

    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password', '123456'),
    );

    expect($authorization->verifiedFactor)->toBe(SensitiveActionFactor::EMAIL)
        ->and($code->refresh()->consumed_at)->not->toBeNull();
});

it('rate limits repeated invalid sensitive-action passwords', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext('infrastructure.secret.reveal', 'secret-uuid');

    foreach (range(1, 5) as $_) {
        try {
            $authorizer->authorize(
                $user,
                $context,
                new SensitiveActionCredentials('invalid-password'),
            );
        } catch (ValidationException) {
            // Expected failed first-factor attempt.
        }
    }

    expect(fn () => $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    ))->toThrow(ValidationException::class, 'Too many verification attempts');
});

it('rejects forged and cloned sensitive-action authorizations', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-uuid',
    );
    $forged = new SensitiveActionAuthorization(
        $context,
        null,
        new DateTimeImmutable,
    );

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        $forged,
    ))->toThrow(AuthorizationException::class);

    $issued = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    );

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        clone $issued,
    ))->toThrow(AuthorizationException::class);

    // A rejected clone does not consume the exact issued object.
    $authorizer->consume($user, $context, $issued);
});

it('consumes a sensitive-action authorization exactly once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-uuid',
    );
    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    );

    $authorizer->consume($user, $context, $authorization);

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        $authorization,
    ))->toThrow(AuthorizationException::class);
});

it('rejects an authorization for a different actor or context', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-one',
    );

    $this->actingAs($first);
    $crossActor = $authorizer->authorize(
        $first,
        $context,
        new SensitiveActionCredentials('password'),
    );
    $this->actingAs($second);

    expect(fn () => $authorizer->consume(
        $second,
        $context,
        $crossActor,
    ))->toThrow(AuthorizationException::class);

    $this->actingAs($first);
    $crossContext = $authorizer->authorize(
        $first,
        $context,
        new SensitiveActionCredentials('password'),
    );

    expect(fn () => $authorizer->consume(
        $first,
        new SensitiveActionContext(
            'infrastructure.secret.reveal',
            'secret-two',
        ),
        $crossContext,
    ))->toThrow(AuthorizationException::class);
});

it('binds an authorization to the current request or session', function () {
    $user = User::factory()->create();
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-uuid',
    );
    $issuedRequest = Request::create('/admin', 'POST');
    $issuedRequest->headers->set('Authorization', 'Bearer request-a');
    $issuedRequest->setUserResolver(static fn (): User => $user);
    $otherRequest = Request::create('/admin', 'POST');
    $otherRequest->headers->set('Authorization', 'Bearer request-b');
    $otherRequest->setUserResolver(static fn (): User => $user);
    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
        $issuedRequest,
    );

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        $authorization,
        $otherRequest,
    ))->toThrow(AuthorizationException::class);
});

it('expires unconsumed sensitive-action authorizations quickly', function () {
    config()->set(
        'starter-kit.sensitive_action.authorization_ttl_seconds',
        1,
    );
    $user = User::factory()->create();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-uuid',
    );
    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    );

    usleep(1_100_000);

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        $authorization,
    ))->toThrow(AuthorizationException::class);
});

it('invalidates authorization when the authentication state changes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $authorizer = app(SensitiveActionAuthorizer::class);
    $context = new SensitiveActionContext(
        'infrastructure.secret.reveal',
        'secret-uuid',
    );
    $authorization = $authorizer->authorize(
        $user,
        $context,
        new SensitiveActionCredentials('password'),
    );
    $user->forceFill(['password' => Hash::make('changed-password')])->save();

    expect(fn () => $authorizer->consume(
        $user,
        $context,
        $authorization,
    ))->toThrow(AuthorizationException::class);
});
