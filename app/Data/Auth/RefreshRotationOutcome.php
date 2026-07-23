<?php

namespace App\Data\Auth;

use App\Enums\RefreshRotationStatus;
use App\Models\ApiDeviceSession;

readonly class RefreshRotationOutcome
{
    public function __construct(
        public RefreshRotationStatus $status,
        public ?IssuedMobileTokenPair $tokenPair = null,
        public ?ApiDeviceSession $deviceSession = null,
    ) {}

    public static function success(IssuedMobileTokenPair $tokenPair): self
    {
        return new self(RefreshRotationStatus::SUCCESS, $tokenPair, $tokenPair->deviceSession);
    }

    public static function invalid(?ApiDeviceSession $deviceSession = null): self
    {
        return new self(RefreshRotationStatus::INVALID, deviceSession: $deviceSession);
    }

    public static function inactive(ApiDeviceSession $deviceSession): self
    {
        return new self(RefreshRotationStatus::INACTIVE, deviceSession: $deviceSession);
    }

    public static function reused(ApiDeviceSession $deviceSession): self
    {
        return new self(RefreshRotationStatus::REUSED, deviceSession: $deviceSession);
    }
}
