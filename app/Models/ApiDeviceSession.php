<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Enums\TokenRevokeReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ramsey\Uuid\Uuid;

class ApiDeviceSession extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_uuid',
        'device_id_hash',
        'abilities',
        'device_name',
        'platform',
        'app_version',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'refresh_expires_at',
        'absolute_expires_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected $hidden = [
        'device_id_hash',
        'abilities',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'platform' => DevicePlatform::class,
            'revoke_reason' => TokenRevokeReason::class,
            'last_seen_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'api_device_session_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'api_device_session_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('refresh_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->refresh_expires_at->isFuture()
            && $this->absolute_expires_at->isFuture();
    }

    public function newUniqueId(): string
    {
        return (string) Uuid::uuid7();
    }

    public function uniqueIds(): array
    {
        return ['id'];
    }
}
