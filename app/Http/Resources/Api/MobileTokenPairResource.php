<?php

namespace App\Http\Resources\Api;

use App\Data\Auth\IssuedMobileTokenPair;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IssuedMobileTokenPair */
class MobileTokenPairResource extends JsonResource
{
    private const array SENSITIVE_RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];

    /**
     * @return array{
     *     user: UserResource,
     *     access_token: string,
     *     token_type: 'Bearer',
     *     expires_in: int,
     *     expires_at: CarbonInterface,
     *     refresh_token: string,
     *     refresh_expires_at: CarbonInterface,
     *     device_session_id: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->user),
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) max(0, $this->accessExpiresAt->getTimestamp() - now()->getTimestamp()),
            'expires_at' => $this->accessExpiresAt,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_at' => $this->refreshExpiresAt,
            'device_session_id' => (string) $this->deviceSession->getKey(),
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->add(self::SENSITIVE_RESPONSE_HEADERS);
    }
}
