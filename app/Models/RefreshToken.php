<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

class RefreshToken extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'api_device_session_id',
        'token_hash',
        'replaced_by_id',
        'rotation_request_hash',
        'rotation_device_hash',
        'rotation_response',
        'rotation_expires_at',
        'used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'rotation_request_hash',
        'rotation_device_hash',
        'rotation_response',
    ];

    protected function casts(): array
    {
        return [
            'rotation_expires_at' => 'datetime',
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(ApiDeviceSession::class, 'api_device_session_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public static function tokenHash(string $secret): string
    {
        return hash('sha256', $secret);
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
