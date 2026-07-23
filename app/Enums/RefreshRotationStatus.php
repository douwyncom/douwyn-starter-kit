<?php

namespace App\Enums;

enum RefreshRotationStatus: string
{
    case SUCCESS = 'success';
    case INVALID = 'invalid';
    case INACTIVE = 'inactive';
    case REUSED = 'reused';
}
