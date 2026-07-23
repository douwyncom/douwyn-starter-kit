<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum UserGender: string implements HasLabel
{
    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::MALE => __('enum.male'),
            self::FEMALE => __('enum.female'),
            self::OTHER => __('enum.other'),
        };
    }
}
