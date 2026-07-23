<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MobileTokenSettings
{
    public function accessMinutes(): int
    {
        return $this->positiveInteger('auth_tokens.access_minutes', 15);
    }

    public function refreshIdleDays(): int
    {
        return $this->positiveInteger('auth_tokens.refresh_idle_days', 30);
    }

    public function refreshAbsoluteDays(): int
    {
        return max(
            $this->refreshIdleDays(),
            $this->positiveInteger('auth_tokens.refresh_absolute_days', 90),
        );
    }

    public function replaySeconds(): int
    {
        return min(300, max(10, (int) config('auth_tokens.refresh_replay_seconds', 60)));
    }

    public function lockSeconds(): int
    {
        return min(120, max(10, (int) config('auth_tokens.refresh_lock_seconds', 30)));
    }

    public function accessExpiresAt(?CarbonInterface $from = null): CarbonImmutable
    {
        return $this->immutable($from)->addMinutes($this->accessMinutes());
    }

    public function absoluteExpiresAt(?CarbonInterface $from = null): CarbonImmutable
    {
        return $this->immutable($from)->addDays($this->refreshAbsoluteDays());
    }

    public function refreshExpiresAt(CarbonInterface $absoluteExpiresAt, ?CarbonInterface $from = null): CarbonImmutable
    {
        $idleExpiry = $this->immutable($from)->addDays($this->refreshIdleDays());
        $absoluteExpiry = CarbonImmutable::instance($absoluteExpiresAt);

        return $idleExpiry->lessThan($absoluteExpiry) ? $idleExpiry : $absoluteExpiry;
    }

    private function positiveInteger(string $configKey, int $default): int
    {
        return max(1, (int) config($configKey, $default));
    }

    private function immutable(?CarbonInterface $value): CarbonImmutable
    {
        return $value ? CarbonImmutable::instance($value) : CarbonImmutable::instance(now());
    }
}
