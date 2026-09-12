<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordWithinHashLimit implements ValidationRule
{
    public function __construct(private readonly int $maxBytes = 72) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.string')->translate();

            return;
        }

        if (strlen($value) > $this->maxBytes) {
            $fail('validation.max_bytes')->translate([
                'attribute' => $attribute,
                'max' => $this->maxBytes,
            ]);
        }
    }
}
