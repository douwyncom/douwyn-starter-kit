<?php

namespace App\Models;

use App\Enums\TokenRevokeReason;
use App\Enums\TwoFactorMethod;
use App\Services\Auth\AccessRevocationService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasAuditLogs, HasFactory, HasRoles, HasUuids, Notifiable;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'two_factor_method',
        'is_inactive',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_method' => TwoFactorMethod::class,
            'two_factor_last_used_timestamp' => 'integer',
            'is_inactive' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
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

    /**
     * Define user can login panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->is_inactive
            && $this->hasAnyRole(['super_admin', 'admin'])
            && $this->hasPermissionTo('panel.access');
    }

    /**
     * Return name
     */
    public function getFilamentName(): string
    {
        return $this->profile?->first_name ?: $this->email;
    }

    public function preferredLocale(): string
    {
        $locale = $this->profile?->locale;

        return in_array($locale, ['en', 'vi'], true)
            ? $locale
            : (string) config('app.locale');
    }

    public function hasEnabledTwoFactor(?TwoFactorMethod $method = null): bool
    {
        $configuredMethod = $this->two_factor_method ?? TwoFactorMethod::NONE;

        if ($configuredMethod === TwoFactorMethod::NONE || ($method && $configuredMethod !== $method)) {
            return false;
        }

        return $this->two_factor_confirmed_at !== null && $this->two_factor_enabled_at !== null;
    }

    /**
     * 1-1 with the user_profiles table
     */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function loginSessions(): HasMany
    {
        return $this->hasMany(LoginSession::class, 'user_uuid', 'uuid');
    }

    public function apiDeviceSessions(): HasMany
    {
        return $this->hasMany(ApiDeviceSession::class, 'user_uuid', 'uuid');
    }

    public function accountActionTokens(): HasMany
    {
        return $this->hasMany(AccountActionToken::class, 'user_uuid', 'uuid');
    }

    public static function activeSuperAdministrators(): Builder
    {
        return static::query()
            ->where('is_inactive', false)
            ->role('super_admin');
    }

    public function isLastActiveSuperAdministrator(): bool
    {
        return ! $this->is_inactive
            && $this->hasRole('super_admin')
            && ! static::activeSuperAdministrators()
                ->where($this->getKeyName(), '!=', $this->getKey())
                ->exists();
    }

    public function invalidateRememberedLogin(): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('is_inactive') && $user->is_inactive) {
                app(AccessRevocationService::class)->revokeAll(
                    $user,
                    TokenRevokeReason::ACCOUNT_INACTIVE,
                );
            }
        });

        static::deleting(function (User $user): void {
            $user->tokens()->delete();
        });
    }
}
