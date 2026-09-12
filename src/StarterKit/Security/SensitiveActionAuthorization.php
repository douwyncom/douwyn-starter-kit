<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Security;

use DateTimeImmutable;

final readonly class SensitiveActionAuthorization
{
    /**
     * @internal Instances are valid only when issued and consumed by the same
     * SensitiveActionAuthorizer request scope. Constructing or cloning this DTO
     * does not create authorization.
     */
    public function __construct(
        public SensitiveActionContext $context,
        public ?SensitiveActionFactor $verifiedFactor,
        public DateTimeImmutable $authorizedAt,
    ) {}

    public function usedRecoveryCode(): bool
    {
        return $this->verifiedFactor === SensitiveActionFactor::RECOVERY_CODE;
    }
}
