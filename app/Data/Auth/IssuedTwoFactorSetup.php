<?php

namespace App\Data\Auth;

use App\Models\TwoFactorSetup;

readonly class IssuedTwoFactorSetup
{
    public function __construct(
        public TwoFactorSetup $setup,
        public string $plainTextToken,
        public ?string $secret = null,
        public ?string $otpauthUri = null,
    ) {}
}
