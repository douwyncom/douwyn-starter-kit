<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Security;

enum SensitiveActionFactor: string
{
    case AUTHENTICATOR = 'app';
    case EMAIL = 'email';
    case RECOVERY_CODE = 'recovery_code';
}
