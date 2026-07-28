<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedBackedEnum;
use App\Casts\EncryptedDate;
use App\Enums\UserGender;
use Database\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory, HasUuids;

    public const array ENCRYPTED_ATTRIBUTES = [
        'first_name',
        'last_name',
        'birthdate',
        'gender',
        'phone',
        'country',
        'city',
        'address_line1',
        'address_line2',
        'postal_code',
        'avatar_url',
        'bio',
        'website',
        'locale',
        'timezone',
        'metadata',
    ];

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = self::ENCRYPTED_ATTRIBUTES;

    protected $fillable = [
        'user_uuid',
        'first_name',
        'last_name',
        'birthdate',
        'gender',
        'phone',
        'country',
        'city',
        'address_line1',
        'address_line2',
        'postal_code',
        'avatar_url',
        'bio',
        'website',
        'locale',
        'timezone',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'birthdate' => EncryptedDate::class,
            'gender' => EncryptedBackedEnum::class.':'.UserGender::class,
            'phone' => 'encrypted',
            'country' => 'encrypted',
            'city' => 'encrypted',
            'address_line1' => 'encrypted',
            'address_line2' => 'encrypted',
            'postal_code' => 'encrypted',
            'avatar_url' => 'encrypted',
            'bio' => 'encrypted',
            'website' => 'encrypted',
            'locale' => 'encrypted',
            'timezone' => 'encrypted',
            'metadata' => 'encrypted:array',
        ];
    }

    /**
     * 1-1 with 'users' table
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * Generate a new UUID for the model
     */
    public function newUniqueId(): string
    {
        return (string) Uuid::uuid7();
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::creating(function (UserProfile $profile): void {
            if ($profile->getAttribute('gender') === null) {
                $profile->setAttribute('gender', UserGender::OTHER);
            }
        });
    }
}
