<?php

namespace App\Data\Auth;

use App\Models\AuthChallenge;

readonly class IssuedAuthChallenge
{
    public function __construct(
        public AuthChallenge $challenge,
        public string $plainTextToken,
    ) {}
}
