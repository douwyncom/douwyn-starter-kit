<?php

namespace App\Models;

use App\Enums\ApiClientType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'api_device_session_id',
        'client_type',
        'issued_ip_address',
        'issued_user_agent',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'client_type' => ApiClientType::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(ApiDeviceSession::class, 'api_device_session_id');
    }
}
