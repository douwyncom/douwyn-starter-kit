<?php

namespace App\Http\Resources\Api;

use App\Data\Auth\LoginSessionData;
use App\Models\LoginSession;
use App\Services\Auth\LoginSessionIdentifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoginSession */
class LoginSessionResource extends JsonResource
{
    public const string CURRENT_SESSION_ATTRIBUTE = 'current_login_session_raw_id';

    public function toArray(Request $request): LoginSessionData
    {
        $currentSessionId = $request->attributes->get(self::CURRENT_SESSION_ATTRIBUTE);

        return new LoginSessionData(
            id: app(LoginSessionIdentifier::class)->forSession($this->resource),
            device: $this->device_label,
            ip_address: $this->ip_address,
            last_active_at: $this->last_active_at,
            expires_at: $this->last_active_at?->copy()->addMinutes((int) config('session.lifetime', 120)),
            created_at: $this->created_at,
            current: is_string($currentSessionId)
                && hash_equals($currentSessionId, (string) $this->getKey()),
        );
    }
}
