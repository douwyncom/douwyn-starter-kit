<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Auth;

enum TokenAbilityProfile: string
{
    case LEGACY = 'legacy';
    case MOBILE = 'mobile';
}
