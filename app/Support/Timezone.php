<?php

namespace App\Support;

use DateTimeZone;

class Timezone
{
    public static function current(): string
    {
        return auth()->user()?->profile?->timezone
            ?? Settings::get('general', 'timezone', config('app.timezone'));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }
}
