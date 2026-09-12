<?php

namespace App\Services\Auth;

use App\Data\Auth\IssuedMobileTokenPair;
use App\Data\Auth\MobileDeviceData;
use App\Data\Auth\RefreshRotationOutcome;
use App\Enums\ApiClientType;
use App\Enums\RefreshRotationStatus;
use App\Enums\TokenRevokeReason;
use App\Events\RefreshTokenReused;
use App\Models\ApiDeviceSession;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Security\SecurityTelemetry;
use Carbon\CarbonImmutable;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class MobileTokenService
{
    public function __construct(
        private readonly MobileTokenSettings $settings,
        private readonly DeviceFingerprint $fingerprint,
        private readonly AuthSignature $authSignature,
        private readonly SecurityTelemetry $telemetry,
        private readonly TokenAbilityRegistry $abilityRegistry,
    ) {}

    public function issue(
        User $user,
        MobileDeviceData $device,
        ?array $abilities = null,
    ): IssuedMobileTokenPair {
        $abilities ??= $this->abilityRegistry->abilitiesFor(TokenAbilityProfile::MOBILE);
        $abilities = $this->normalizeAbilities($abilities);
        $expectedAuthSignature = $this->authSignature->for($user);

        return DB::transaction(function () use ($user, $device, $abilities, $expectedAuthSignature): IssuedMobileTokenPair {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->is_inactive) {
                $this->rejectInactiveAccount();
            }

            if (! hash_equals($expectedAuthSignature, $this->authSignature->for($lockedUser))) {
                $this->rejectAuthenticationStateChanged();
            }

            ApiDeviceSession::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->where('device_id_hash', $device->deviceIdHash)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (ApiDeviceSession $session) => $this->revokeLockedDeviceSession(
                    $session,
                    TokenRevokeReason::REPLACED_BY_NEW_LOGIN,
                ));

            $now = CarbonImmutable::instance(now());
            $absoluteExpiresAt = $this->settings->absoluteExpiresAt($now);
            $refreshExpiresAt = $this->settings->refreshExpiresAt($absoluteExpiresAt, $now);

            $deviceSession = ApiDeviceSession::query()->create([
                'user_uuid' => $lockedUser->uuid,
                'device_id_hash' => $device->deviceIdHash,
                'abilities' => $abilities,
                'device_name' => $device->deviceName,
                'platform' => $device->platform,
                'app_version' => $device->appVersion,
                'ip_address' => $device->ipAddress,
                'user_agent' => $device->userAgent,
                'last_seen_at' => $now,
                'refresh_expires_at' => $refreshExpiresAt,
                'absolute_expires_at' => $absoluteExpiresAt,
            ]);

            [$refreshToken, $plainTextRefreshToken] = $this->createRefreshToken(
                $deviceSession,
                $refreshExpiresAt,
            );
            [$plainTextAccessToken, $accessExpiresAt] = $this->createAccessToken(
                $lockedUser,
                $deviceSession,
                $abilities,
                $device->ipAddress,
                $device->userAgent,
            );

            return new IssuedMobileTokenPair(
                user: $lockedUser->load('profile'),
                deviceSession: $deviceSession,
                accessToken: $plainTextAccessToken,
                accessExpiresAt: $accessExpiresAt,
                refreshToken: $plainTextRefreshToken,
                refreshExpiresAt: $refreshToken->expires_at,
            );
        });
    }

    public function rotate(
        string $plainTextRefreshToken,
        string $deviceId,
        Request $request,
    ): IssuedMobileTokenPair {
        $requestId = trim((string) $request->input('request_id'));

        if (! Str::isUuid($requestId)) {
            throw new HttpResponseException(response()->json([
                'message' => __('api.errors.validation_failed'),
                'errors' => [
                    'request_id' => [__('auth.errors.request_id_invalid_uuid')],
                ],
            ], 422));
        }

        $requestHash = hash('sha256', mb_strtolower($requestId));
        $deviceIdHash = $this->fingerprint->hash($deviceId);
        $cacheKey = 'mobile-refresh-replay:'.hash(
            'sha256',
            $plainTextRefreshToken."\0".$requestHash."\0".$deviceIdHash,
        );
        $rotationStarted = false;

        try {
            return Cache::lock("$cacheKey:lock", $this->settings->lockSeconds())->block(5, function () use (
                $cacheKey,
                $plainTextRefreshToken,
                $deviceIdHash,
                $requestHash,
                $request,
                &$rotationStarted,
            ): IssuedMobileTokenPair {
                try {
                    $cached = Cache::get($cacheKey);
                } catch (Throwable $exception) {
                    report($exception);
                    $cached = null;
                }

                if (is_string($cached)) {
                    $pair = $this->restoreReplayPair($cached);

                    if ($pair) {
                        return $pair;
                    }

                    try {
                        Cache::forget($cacheKey);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                $rotationStarted = true;
                $pair = $this->rotateOnce(
                    $plainTextRefreshToken,
                    $deviceIdHash,
                    $requestHash,
                    $request,
                );

                try {
                    Cache::put(
                        $cacheKey,
                        $this->encryptPairForReplay($pair),
                        now()->addSeconds($this->settings->replaySeconds()),
                    );
                } catch (Throwable $exception) {
                    // The encrypted replay is persisted in the same database
                    // transaction as rotation; cache remains an optional fast path.
                    report($exception);
                }

                return $pair;
            });
        } catch (LockTimeoutException) {
            throw new HttpResponseException(response()->json([
                'message' => __('auth.errors.refresh_in_progress'),
                'code' => 'refresh_in_progress',
            ], 409));
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($rotationStarted) {
                throw $exception;
            }

            // A cache outage must not make the database-backed refresh flow
            // unavailable. Row locks and persisted replays retain correctness.
            report($exception);

            return $this->rotateOnce(
                $plainTextRefreshToken,
                $deviceIdHash,
                $requestHash,
                $request,
            );
        }
    }

    private function rotateOnce(
        string $plainTextRefreshToken,
        string $deviceIdHash,
        string $requestHash,
        Request $request,
    ): IssuedMobileTokenPair {
        $parts = $this->parseRefreshToken($plainTextRefreshToken);

        if ($parts === null) {
            $this->rejectRefreshToken();
        }

        [$refreshTokenId, $refreshSecret] = $parts;
        $candidate = RefreshToken::query()
            ->select(['id', 'api_device_session_id'])
            ->whereKey($refreshTokenId)
            ->first();

        if (! $candidate) {
            $this->rejectRefreshToken();
        }

        $sessionHint = ApiDeviceSession::query()
            ->select(['id', 'user_uuid'])
            ->whereKey($candidate->api_device_session_id)
            ->first();

        if (! $sessionHint) {
            $this->rejectRefreshToken();
        }

        $outcome = DB::transaction(function () use (
            $sessionHint,
            $refreshTokenId,
            $refreshSecret,
            $deviceIdHash,
            $requestHash,
            $request,
        ): RefreshRotationOutcome {
            $user = User::query()->whereKey($sessionHint->user_uuid)->lockForUpdate()->first();
            $deviceSession = ApiDeviceSession::query()->whereKey($sessionHint->id)->lockForUpdate()->first();
            $refreshToken = RefreshToken::query()->whereKey($refreshTokenId)->lockForUpdate()->first();

            if (! $user || ! $deviceSession || ! $refreshToken
                || $refreshToken->api_device_session_id !== $deviceSession->getKey()
                || ! hash_equals($refreshToken->token_hash, RefreshToken::tokenHash($refreshSecret))
                || ! hash_equals($deviceSession->device_id_hash, $deviceIdHash)) {
                return RefreshRotationOutcome::invalid($deviceSession);
            }

            if ($refreshToken->used_at !== null) {
                if ($this->isMatchingReplay($refreshToken, $requestHash, $deviceIdHash)) {
                    $pair = $this->restoreReplayPair((string) $refreshToken->rotation_response);

                    return $pair
                        ? RefreshRotationOutcome::success($pair)
                        : RefreshRotationOutcome::invalid($deviceSession);
                }

                if ($deviceSession->revoked_at === null) {
                    $this->revokeLockedDeviceSession(
                        $deviceSession,
                        TokenRevokeReason::REFRESH_TOKEN_REUSED,
                    );

                    return RefreshRotationOutcome::reused($deviceSession->fresh());
                }

                return RefreshRotationOutcome::invalid($deviceSession);
            }

            if ($deviceSession->revoked_at !== null || $refreshToken->revoked_at !== null) {
                return RefreshRotationOutcome::invalid($deviceSession);
            }

            if ($user->is_inactive) {
                $this->revokeLockedDeviceSession($deviceSession, TokenRevokeReason::ACCOUNT_INACTIVE);

                return RefreshRotationOutcome::inactive($deviceSession->fresh());
            }

            if ($refreshToken->expires_at->isPast()
                || $deviceSession->refresh_expires_at->isPast()
                || $deviceSession->absolute_expires_at->isPast()) {
                $this->revokeLockedDeviceSession($deviceSession, TokenRevokeReason::EXPIRED);

                return RefreshRotationOutcome::invalid($deviceSession->fresh());
            }

            if ($deviceSession->abilities === null) {
                $this->revokeLockedDeviceSession($deviceSession, TokenRevokeReason::SECURITY_CHANGED);

                return RefreshRotationOutcome::invalid($deviceSession->fresh());
            }

            $now = CarbonImmutable::instance(now());
            $refreshExpiresAt = $this->settings->refreshExpiresAt(
                $deviceSession->absolute_expires_at,
                $now,
            );
            [$replacement, $plainTextReplacement] = $this->createRefreshToken(
                $deviceSession,
                $refreshExpiresAt,
            );

            $refreshToken->forceFill([
                'used_at' => $now,
                'replaced_by_id' => $replacement->getKey(),
            ])->save();

            PersonalAccessToken::query()
                ->where('api_device_session_id', $deviceSession->getKey())
                ->delete();

            $ipAddress = $request->ip();
            $userAgent = $this->nullableString(mb_substr((string) $request->userAgent(), 0, 1000));
            [$plainTextAccessToken, $accessExpiresAt] = $this->createAccessToken(
                $user,
                $deviceSession,
                $this->abilitiesFor($deviceSession),
                $ipAddress,
                $userAgent,
            );

            $deviceSession->forceFill([
                'app_version' => $this->nullableString($request->input('app_version')) ?? $deviceSession->app_version,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'last_seen_at' => $now,
                'refresh_expires_at' => $refreshExpiresAt,
            ])->save();

            $pair = new IssuedMobileTokenPair(
                user: $user->load('profile'),
                deviceSession: $deviceSession,
                accessToken: $plainTextAccessToken,
                accessExpiresAt: $accessExpiresAt,
                refreshToken: $plainTextReplacement,
                refreshExpiresAt: $replacement->expires_at,
            );

            $refreshToken->forceFill([
                'rotation_request_hash' => $requestHash,
                'rotation_device_hash' => $deviceIdHash,
                'rotation_response' => $this->encryptPairForReplay($pair),
                'rotation_expires_at' => $now->addSeconds($this->settings->replaySeconds()),
            ])->save();

            return RefreshRotationOutcome::success($pair);
        });

        if ($outcome->status === RefreshRotationStatus::SUCCESS && $outcome->tokenPair) {
            return $outcome->tokenPair;
        }

        if ($outcome->status === RefreshRotationStatus::REUSED && $outcome->deviceSession) {
            $this->telemetry->refreshTokenReused(
                $outcome->deviceSession,
                $request->ip(),
                $this->nullableString(mb_substr((string) $request->userAgent(), 0, 1000)),
            );

            try {
                RefreshTokenReused::dispatch(
                    $outcome->deviceSession,
                    $request->ip(),
                    $this->nullableString(mb_substr((string) $request->userAgent(), 0, 1000)),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($outcome->status === RefreshRotationStatus::INACTIVE) {
            $this->rejectInactiveAccount();
        }

        // This exception is intentionally raised after the transaction commits. In
        // particular, a refresh-token reuse must never roll back family revocation.
        $this->rejectRefreshToken();
    }

    public function logout(string $plainTextRefreshToken, string $deviceId): void
    {
        $parts = $this->parseRefreshToken($plainTextRefreshToken);

        if ($parts === null) {
            return;
        }

        [$refreshTokenId, $refreshSecret] = $parts;
        $candidate = RefreshToken::query()
            ->select(['id', 'api_device_session_id'])
            ->whereKey($refreshTokenId)
            ->first();

        if (! $candidate) {
            return;
        }

        $deviceIdHash = $this->fingerprint->hash($deviceId);

        DB::transaction(function () use ($candidate, $refreshSecret, $deviceIdHash): void {
            $deviceSession = ApiDeviceSession::query()
                ->whereKey($candidate->api_device_session_id)
                ->lockForUpdate()
                ->first();
            $refreshToken = RefreshToken::query()->whereKey($candidate->getKey())->lockForUpdate()->first();

            if (! $deviceSession || ! $refreshToken
                || $refreshToken->api_device_session_id !== $deviceSession->getKey()
                || ! hash_equals($refreshToken->token_hash, RefreshToken::tokenHash($refreshSecret))
                || ! hash_equals($deviceSession->device_id_hash, $deviceIdHash)) {
                return;
            }

            $this->revokeLockedDeviceSession($deviceSession, TokenRevokeReason::LOGOUT);
        });
    }

    public function revokeDeviceSession(
        ApiDeviceSession $deviceSession,
        TokenRevokeReason $reason,
        bool $anonymous = false,
    ): void {
        DB::transaction(function () use ($deviceSession, $reason, $anonymous): void {
            $lockedSession = ApiDeviceSession::query()
                ->whereKey($deviceSession->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedSession) {
                $this->revokeLockedDeviceSession($lockedSession, $reason, $anonymous);
            }
        });
    }

    public function revokeAll(
        User $user,
        TokenRevokeReason $reason,
        bool $anonymous = false,
    ): void {
        DB::transaction(function () use ($user, $reason, $anonymous): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser) {
                return;
            }

            ApiDeviceSession::query()
                ->where('user_uuid', $lockedUser->uuid)
                ->lockForUpdate()
                ->get()
                ->each(fn (ApiDeviceSession $session) => $this->revokeLockedDeviceSession(
                    $session,
                    $reason,
                    $anonymous,
                ));
        });
    }

    /** @return array{RefreshToken, string} */
    private function createRefreshToken(
        ApiDeviceSession $deviceSession,
        CarbonImmutable $expiresAt,
    ): array {
        $secret = 'douwyn_rt_'.Str::random(64);
        $refreshToken = RefreshToken::query()->create([
            'api_device_session_id' => $deviceSession->getKey(),
            'token_hash' => RefreshToken::tokenHash($secret),
            'expires_at' => $expiresAt,
        ]);

        return [$refreshToken, $refreshToken->getKey().'|'.$secret];
    }

    /** @return array{string, CarbonImmutable} */
    private function createAccessToken(
        User $user,
        ApiDeviceSession $deviceSession,
        array $abilities,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $expiresAt = $this->settings->accessExpiresAt();
        $issuedToken = $user->createToken(
            $deviceSession->device_name,
            $abilities,
            $expiresAt,
        );

        if (! $issuedToken->accessToken instanceof PersonalAccessToken) {
            throw new LogicException('Register App\\Models\\PersonalAccessToken with Laravel Sanctum.');
        }

        $issuedToken->accessToken->forceFill([
            'api_device_session_id' => $deviceSession->getKey(),
            'client_type' => ApiClientType::MOBILE,
            'issued_ip_address' => $ipAddress,
            'issued_user_agent' => $userAgent,
        ])->save();

        return [$issuedToken->plainTextToken, $expiresAt];
    }

    private function revokeLockedDeviceSession(
        ApiDeviceSession $deviceSession,
        TokenRevokeReason $reason,
        bool $anonymous = false,
    ): void {
        $now = now();

        if ($deviceSession->revoked_at === null) {
            $deviceSession->forceFill([
                'revoked_at' => $now,
                'revoke_reason' => $reason,
            ])->save();

            $this->telemetry->deviceSessionRevoked(
                $deviceSession,
                $reason,
                app()->bound('request') ? request() : null,
                $anonymous,
            );
        }

        RefreshToken::query()
            ->where('api_device_session_id', $deviceSession->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        PersonalAccessToken::query()
            ->where('api_device_session_id', $deviceSession->getKey())
            ->delete();
    }

    /** @return array{string, string}|null */
    private function parseRefreshToken(string $plainTextToken): ?array
    {
        if (! str_contains($plainTextToken, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plainTextToken, 2);

        if (! Str::isUuid($id)
            || ! str_starts_with($secret, 'douwyn_rt_')
            || mb_strlen($secret) < 72
            || mb_strlen($secret) > 128) {
            return null;
        }

        return [$id, $secret];
    }

    private function encryptPairForReplay(IssuedMobileTokenPair $pair): string
    {
        return Crypt::encryptString(json_encode([
            'user_uuid' => $pair->user->uuid,
            'device_session_id' => $pair->deviceSession->getKey(),
            'access_token' => $pair->accessToken,
            'access_expires_at' => $pair->accessExpiresAt->toIso8601String(),
            'refresh_token' => $pair->refreshToken,
            'refresh_expires_at' => $pair->refreshExpiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function restoreReplayPair(string $encryptedPayload): ?IssuedMobileTokenPair
    {
        try {
            $payload = json_decode(
                Crypt::decryptString($encryptedPayload),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $user = User::query()->with('profile')->find($payload['user_uuid'] ?? null);
            $deviceSession = ApiDeviceSession::query()->find($payload['device_session_id'] ?? null);
            $accessToken = (string) ($payload['access_token'] ?? '');
            $storedAccessToken = PersonalAccessToken::findToken($accessToken);

            if (! $user
                || $user->is_inactive
                || ! $deviceSession
                || ! $deviceSession->isActive()
                || ! $storedAccessToken
                || $storedAccessToken->tokenable_id !== $user->getKey()
                || $storedAccessToken->api_device_session_id !== $deviceSession->getKey()
                || $storedAccessToken->expires_at?->isPast()) {
                return null;
            }

            return new IssuedMobileTokenPair(
                user: $user,
                deviceSession: $deviceSession,
                accessToken: $accessToken,
                accessExpiresAt: CarbonImmutable::parse((string) $payload['access_expires_at']),
                refreshToken: (string) $payload['refresh_token'],
                refreshExpiresAt: CarbonImmutable::parse((string) $payload['refresh_expires_at']),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function isMatchingReplay(
        RefreshToken $refreshToken,
        string $requestHash,
        string $deviceIdHash,
    ): bool {
        return is_string($refreshToken->rotation_request_hash)
            && is_string($refreshToken->rotation_device_hash)
            && is_string($refreshToken->rotation_response)
            && $refreshToken->rotation_expires_at?->isFuture()
            && hash_equals($refreshToken->rotation_request_hash, $requestHash)
            && hash_equals($refreshToken->rotation_device_hash, $deviceIdHash);
    }

    /** @return list<string> */
    private function abilitiesFor(ApiDeviceSession $deviceSession): array
    {
        return $this->normalizeAbilities($deviceSession->abilities ?? []);
    }

    /** @return list<string> */
    private function normalizeAbilities(array $abilities): array
    {
        return array_values(array_unique(array_filter(
            $abilities,
            fn (mixed $ability): bool => is_string($ability) && trim($ability) !== '',
        )));
    }

    private function rejectRefreshToken(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => __('auth.errors.sign_in_again'),
            'code' => 'reauthentication_required',
        ], 401));
    }

    private function rejectInactiveAccount(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => __('auth.errors.account_inactive'),
            'code' => 'account_inactive',
        ], 403));
    }

    private function rejectAuthenticationStateChanged(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => __('auth.errors.authentication_state_changed'),
            'code' => 'authentication_state_changed',
        ], 401));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
