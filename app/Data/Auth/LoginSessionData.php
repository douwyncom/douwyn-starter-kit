<?php

namespace App\Data\Auth;

use App\Enums\LoginSessionStatus;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class LoginSessionData implements Arrayable
{
    public function __construct(
        public string $id,
        public string $device,
        public ?string $ip_address,
        public ?CarbonInterface $last_active_at,
        public ?CarbonInterface $expires_at,
        public ?CarbonInterface $created_at,
        public bool $current,
        public LoginSessionStatus $status = LoginSessionStatus::ACTIVE,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'device' => $this->device,
            'ip_address' => $this->ip_address,
            'last_active_at' => $this->last_active_at,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'current' => $this->current,
            'status' => $this->status,
        ];
    }
}
