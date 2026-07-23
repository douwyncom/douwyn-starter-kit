<?php

namespace App\Enums;

enum ApiDeviceSessionStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case REVOKED = 'revoked';
    case COMPROMISED = 'compromised';
}
