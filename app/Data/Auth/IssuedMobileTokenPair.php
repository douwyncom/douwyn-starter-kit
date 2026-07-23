<?php

namespace App\Data\Auth;

use App\Models\ApiDeviceSession;
use App\Models\User;
use Carbon\CarbonInterface;

readonly class IssuedMobileTokenPair
{
    public function __construct(
        public User $user,
        public ApiDeviceSession $deviceSession,
        public string $accessToken,
        public CarbonInterface $accessExpiresAt,
        public string $refreshToken,
        public CarbonInterface $refreshExpiresAt,
    ) {}
}
