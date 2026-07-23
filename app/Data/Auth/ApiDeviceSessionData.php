<?php

namespace App\Data\Auth;

use App\Enums\ApiDeviceSessionStatus;
use App\Enums\DevicePlatform;
use App\Enums\TokenRevokeReason;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class ApiDeviceSessionData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $device_name,
        public DevicePlatform $platform,
        public ?string $app_version,
        public ?string $ip_address,
        public ?CarbonInterface $last_seen_at,
        public CarbonInterface $refresh_expires_at,
        public CarbonInterface $absolute_expires_at,
        public ?CarbonInterface $revoked_at,
        public ?TokenRevokeReason $revoke_reason,
        public ApiDeviceSessionStatus $status,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'ip_address' => $this->ip_address,
            'last_seen_at' => $this->last_seen_at,
            'refresh_expires_at' => $this->refresh_expires_at,
            'absolute_expires_at' => $this->absolute_expires_at,
            'revoked_at' => $this->revoked_at,
            'revoke_reason' => $this->revoke_reason,
            'status' => $this->status,
        ];
    }
}
