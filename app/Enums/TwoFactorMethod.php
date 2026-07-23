<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum TwoFactorMethod: string implements HasLabel
{
    case NONE = 'none';
    case EMAIL = 'email';
    case APP = 'app';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::NONE => __('enum.none'),
            self::EMAIL => __('enum.email'),
            self::APP => __('enum.app'),
        };
    }
}
