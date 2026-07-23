<?php

namespace App\Models;

use App\Enums\AccountActionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

class AccountActionToken extends Model
{
    use HasUuids;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_uuid',
        'type',
        'token_hash',
        'auth_signature',
        'source_email_hash',
        'target_email',
        'target_email_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'token_hash',
        'auth_signature',
        'source_email_hash',
        'target_email',
        'target_email_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountActionType::class,
            'target_email' => 'encrypted',
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

    public static function tokenHash(string $plainTextToken, AccountActionType $type): string
    {
        return hash_hmac(
            'sha256',
            $type->value."\0".$plainTextToken,
            (string) config('app.key'),
        );
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac(
            'sha256',
            mb_strtolower(trim($email)),
            (string) config('app.key'),
        );
    }
}
