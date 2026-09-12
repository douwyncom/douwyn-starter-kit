<?php

namespace App\Models;

use App\Services\Auth\LoginSessionIdentifier;
use App\Services\Security\SecurityTelemetry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_uuid',
        'ip_address',
        'user_agent',
        'last_active_at',
        'revoked_at',
    ];

    protected $hidden = [
        'public_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LoginSession $session): void {
            if (! $session->public_id_hash && $session->getKey()) {
                $session->public_id_hash = app(LoginSessionIdentifier::class)
                    ->digest((string) $session->getKey());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('last_active_at', '>=', now()->subMinutes((int) config('session.lifetime', 120)));
    }

    public function scopeNotRevoked(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function revoke(string $reason = 'user_revoked', string $scope = 'single_session'): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $revokedAt = now();
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $revokedAt]);

        if ($updated !== 1) {
            return;
        }

        $this->setAttribute('revoked_at', $revokedAt);
        $this->syncOriginalAttribute('revoked_at');
        $user = $this->user;
        $user?->invalidateRememberedLogin();

        if ($user) {
            app(SecurityTelemetry::class)->browserSessionsRevoked(
                $user,
                app()->bound('request') ? request() : null,
                $scope,
                sessionId: (string) $this->getKey(),
                reason: $reason,
            );
        }
    }

    public function getDeviceLabelAttribute(): string
    {
        $agent = mb_strtolower((string) $this->user_agent);

        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'chrome/') => 'Chrome',
            str_contains($agent, 'firefox/') => 'Firefox',
            str_contains($agent, 'safari/') => 'Safari',
            default => __('resources/login_session.devices.unknown_browser'),
        };

        $platform = match (true) {
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => __('resources/login_session.devices.unknown_device'),
        };

        return "$browser · $platform";
    }
}
