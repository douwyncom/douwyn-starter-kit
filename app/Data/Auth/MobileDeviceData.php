<?php

namespace App\Data\Auth;

use App\Enums\DevicePlatform;
use App\Models\AuthChallenge;
use App\Services\Auth\DeviceFingerprint;
use Illuminate\Http\Request;
use InvalidArgumentException;

readonly class MobileDeviceData
{
    public function __construct(
        public string $deviceName,
        public string $deviceIdHash,
        public DevicePlatform $platform,
        public ?string $appVersion,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request, DeviceFingerprint $fingerprint): self
    {
        $deviceId = trim((string) $request->input('device_id'));
        $platform = DevicePlatform::tryFrom((string) $request->input('platform'));

        if ($deviceId === '' || ! $platform) {
            throw new InvalidArgumentException('A valid mobile device ID and platform are required.');
        }

        return new self(
            deviceName: trim((string) $request->input('device_name')),
            deviceIdHash: $fingerprint->hash($deviceId),
            platform: $platform,
            appVersion: self::nullableString($request->input('app_version')),
            ipAddress: $request->ip(),
            userAgent: self::nullableString(mb_substr((string) $request->userAgent(), 0, 1000)),
        );
    }

    public static function fromChallenge(AuthChallenge $challenge): self
    {
        $platform = DevicePlatform::tryFrom((string) $challenge->platform);

        if (blank($challenge->device_id_hash) || ! $platform) {
            throw new InvalidArgumentException('The authentication challenge has no mobile device binding.');
        }

        return new self(
            deviceName: $challenge->device_name ?: 'Mobile',
            deviceIdHash: (string) $challenge->device_id_hash,
            platform: $platform,
            appVersion: self::nullableString($challenge->app_version),
            ipAddress: self::nullableString($challenge->ip_address),
            userAgent: self::nullableString($challenge->user_agent),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
