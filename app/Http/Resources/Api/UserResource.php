<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'two_factor_enabled' => $this->hasEnabledTwoFactor(),
            'profile' => [
                'first_name' => $this->profile?->first_name,
                'last_name' => $this->profile?->last_name,
                'phone' => $this->profile?->phone,
                'locale' => $this->profile?->locale,
                'timezone' => $this->profile?->timezone,
                'avatar_url' => $this->profile?->avatar_url,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
