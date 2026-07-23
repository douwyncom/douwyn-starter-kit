<?php

namespace App\Http\Resources\Api;

use App\Data\Auth\ApiDeviceSessionData;
use App\Enums\ApiDeviceSessionStatus;
use App\Enums\TokenRevokeReason;
use App\Models\ApiDeviceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiDeviceSession */
class ApiDeviceSessionResource extends JsonResource
{
    public function toArray(Request $request): ApiDeviceSessionData
    {
        return new ApiDeviceSessionData(
            id: (string) $this->getKey(),
            device_name: (string) $this->device_name,
            platform: $this->platform,
            app_version: $this->app_version,
            ip_address: $this->ip_address,
            last_seen_at: $this->last_seen_at,
            refresh_expires_at: $this->refresh_expires_at,
            absolute_expires_at: $this->absolute_expires_at,
            revoked_at: $this->revoked_at,
            revoke_reason: $this->revoke_reason,
            status: $this->status(),
        );
    }

    private function status(): ApiDeviceSessionStatus
    {
        if ($this->revoked_at !== null) {
            return $this->revoke_reason === TokenRevokeReason::REFRESH_TOKEN_REUSED
                ? ApiDeviceSessionStatus::COMPROMISED
                : ApiDeviceSessionStatus::REVOKED;
        }

        if ($this->refresh_expires_at->isPast() || $this->absolute_expires_at->isPast()) {
            return ApiDeviceSessionStatus::EXPIRED;
        }

        return ApiDeviceSessionStatus::ACTIVE;
    }
}
