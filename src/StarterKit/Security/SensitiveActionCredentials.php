<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Security;

use InvalidArgumentException;

final readonly class SensitiveActionCredentials
{
    private string $currentPassword;

    private ?string $oneTimePassword;

    private ?string $recoveryCode;

    public function __construct(
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] ?string $oneTimePassword = null,
        #[\SensitiveParameter] ?string $recoveryCode = null,
    ) {
        $oneTimePassword = $this->filledOrNull($oneTimePassword);
        $recoveryCode = $this->filledOrNull($recoveryCode);

        if ($oneTimePassword !== null && $recoveryCode !== null) {
            throw new InvalidArgumentException(
                'Provide either a one-time password or a recovery code, not both.',
            );
        }

        $this->currentPassword = $currentPassword;
        $this->oneTimePassword = $oneTimePassword;
        $this->recoveryCode = $recoveryCode;
    }

    public function currentPassword(): string
    {
        return $this->currentPassword;
    }

    public function oneTimePassword(): ?string
    {
        return $this->oneTimePassword;
    }

    public function recoveryCode(): ?string
    {
        return $this->recoveryCode;
    }

    /** @return array{current_password: string, one_time_password: string, recovery_code: string} */
    public function __debugInfo(): array
    {
        return [
            'current_password' => '[REDACTED]',
            'one_time_password' => '[REDACTED]',
            'recovery_code' => '[REDACTED]',
        ];
    }

    private function filledOrNull(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
