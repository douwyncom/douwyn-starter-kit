<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Security;

use InvalidArgumentException;

final readonly class SensitiveActionRequirements
{
    public function __construct(
        public bool $twoFactorRequired,
        public ?SensitiveActionFactor $twoFactorMethod,
        public bool $recoveryCodeAccepted,
    ) {
        if ($twoFactorRequired !== ($twoFactorMethod !== null)) {
            throw new InvalidArgumentException(
                'A required sensitive-action second factor must declare its method.',
            );
        }

        if ($twoFactorMethod === SensitiveActionFactor::RECOVERY_CODE) {
            throw new InvalidArgumentException(
                'Recovery codes are an alternative, not a configured second-factor method.',
            );
        }
    }

    public function emailChallengeRequired(): bool
    {
        return $this->twoFactorMethod === SensitiveActionFactor::EMAIL;
    }
}
