<?php

namespace App\Models;

use App\Enums\AuthCredentialType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

class AuthChallenge extends Model
{
    use HasUuids;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_uuid',
        'token_hash',
        'credential_type',
        'two_factor_method',
        'auth_signature',
        'session_id_hash',
        'device_name',
        'device_id_hash',
        'platform',
        'app_version',
        'abilities',
        'ip_address',
        'user_agent',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'credential_type' => AuthCredentialType::class,
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'token_hash',
        'auth_signature',
        'session_id_hash',
        'device_id_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function newUniqueId(): string
    {
        return (string) Uuid::uuid7();
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function emailPurpose(): string
    {
        return "auth_challenge.$this->uuid";
    }

    public static function tokenHash(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }
}
