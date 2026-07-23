<?php

namespace App\Events;

use App\Models\ApiDeviceSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefreshTokenReused
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ApiDeviceSession $deviceSession,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
