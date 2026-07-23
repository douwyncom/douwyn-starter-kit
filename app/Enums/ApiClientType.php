<?php

namespace App\Enums;

enum ApiClientType: string
{
    case MOBILE = 'mobile';
    case PERSONAL = 'personal';
    case INTEGRATION = 'integration';
    case LEGACY = 'legacy';
}
