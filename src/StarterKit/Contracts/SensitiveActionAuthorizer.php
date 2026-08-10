<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Douwyn\StarterKit\Security\SensitiveActionAuthorization;
use Douwyn\StarterKit\Security\SensitiveActionContext;
use Douwyn\StarterKit\Security\SensitiveActionCredentials;
use Douwyn\StarterKit\Security\SensitiveActionRequirements;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

interface SensitiveActionAuthorizer
{
    public function requirements(Authenticatable $user): SensitiveActionRequirements;

    /**
     * Send the current email second-factor challenge after verifying the
     * user's password. This method is only valid when email 2FA is enabled.
     */
    public function sendEmailChallenge(
        Authenticatable $user,
        SensitiveActionContext $context,
        #[\SensitiveParameter] string $currentPassword,
        ?Request $request = null,
    ): void;

    /**
     * Verify the password and, when enabled, the current second factor.
     *
     * A successful return authorizes only the immediate action represented by
     * the supplied context. It is not a reusable bearer credential.
     */
    public function authorize(
        Authenticatable $user,
        SensitiveActionContext $context,
        SensitiveActionCredentials $credentials,
        ?Request $request = null,
    ): SensitiveActionAuthorization;

    /**
     * Consume an authorization immediately before the protected operation.
     *
     * The exact object returned by authorize() is single-use, short-lived,
     * and bound to the actor, context, request/session, and auth state.
     */
    public function consume(
        Authenticatable $user,
        SensitiveActionContext $context,
        SensitiveActionAuthorization $authorization,
        ?Request $request = null,
    ): void;
}
