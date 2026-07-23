<?php

namespace App\Models;

use App\Enums\TwoFactorMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

class TwoFactorSetup extends Model
{
    use HasUuids;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_uuid',
        'token_hash',
        'method',
        'auth_signature',
        'secret',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'token_hash',
        'auth_signature',
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'method' => TwoFactorMethod::class,
            'secret' => 'encrypted',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

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

    public static function tokenHash(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    public function emailPurpose(): string
    {
        return "two_factor_setup.{$this->uuid}";
    }
}
