<?php

namespace App\Models;

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

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

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

    protected $casts = [
        'gender' => UserGender::class,
        'birthdate' => 'date',
        'metadata' => 'array',
    ];

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
}
