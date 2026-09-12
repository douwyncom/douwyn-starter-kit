<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * Keep the public contract independent of database schema introspection.
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'uuid' => $this->uuid,
            /** @var string */
            'email' => $this->email,
            /** @var CarbonInterface|null */
            'email_verified_at' => $this->email_verified_at,
            'two_factor_enabled' => $this->hasEnabledTwoFactor(),
            'profile' => [
                /** @var string|null */
                'first_name' => $this->profile?->first_name,
                /** @var string|null */
                'last_name' => $this->profile?->last_name,
                /** @var string|null */
                'phone' => $this->profile?->phone,
                /** @var string|null */
                'locale' => $this->profile?->locale,
                /** @var string|null */
                'timezone' => $this->profile?->timezone,
                /** @var string|null */
                'avatar_url' => $this->profile?->avatar_url,
            ],
            /** @var CarbonInterface|null */
            'created_at' => $this->created_at,
            /** @var CarbonInterface|null */
            'updated_at' => $this->updated_at,
        ];
    }
}
