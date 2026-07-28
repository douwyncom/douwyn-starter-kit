<?php

declare(strict_types=1);

use App\Enums\UserGender;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\ActivityLogSanitizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

function protectedProfileValues(): array
{
    return [
        'first_name' => 'Đỗ “An”',
        'last_name' => 'Nguyễn',
        'birthdate' => '1994-03-21',
        'gender' => UserGender::FEMALE,
        'phone' => '+84 912 345 678',
        'country' => 'Việt Nam',
        'city' => 'Hồ Chí Minh',
        'address_line1' => "12 Nguyễn Huệ\nPhường Bến Nghé",
        'address_line2' => 'Căn hộ 08',
        'postal_code' => '700000',
        'avatar_url' => 'https://cdn.example.test/private/avatar.webp',
        'bio' => 'Thông tin cá nhân có dấu và ký tự “đặc biệt”.',
        'website' => 'https://example.test/about',
        'locale' => 'vi',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'metadata' => [
            'emergency_contact' => 'Nguyễn Văn B',
            'preferences' => ['compact_ui' => true],
        ],
    ];
}

function recreateLegacyUserProfilesTable(): void
{
    Schema::drop('user_profiles');

    Schema::create('user_profiles', function (Blueprint $table): void {
        $table->uuid()->primary();
        $table->uuid('user_uuid')->unique()->index();
        $table->foreign('user_uuid')->references('uuid')->on('users')->cascadeOnDelete();
        $table->string('first_name');
        $table->string('last_name');
        $table->date('birthdate')->nullable();
        $table->enum('gender', ['male', 'female', 'other'])->default('other');
        $table->string('phone', 30)->nullable();
        $table->string('country', 100)->nullable();
        $table->string('city', 100)->nullable();
        $table->string('address_line1')->nullable();
        $table->string('address_line2')->nullable();
        $table->string('postal_code', 30)->nullable();
        $table->string('avatar_url')->nullable();
        $table->text('bio')->nullable();
        $table->string('website')->nullable();
        $table->string('locale', 10)->nullable();
        $table->string('timezone', 50)->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    });
}

it('encrypts protected user profile values at rest and preserves model types', function (): void {
    $user = User::factory()->create();
    $profile = $user->profile()->firstOrFail();
    $values = protectedProfileValues();

    $profile->update($values);

    $raw = DB::table('user_profiles')->where('uuid', $profile->getKey())->firstOrFail();

    foreach (array_diff(
        UserProfile::ENCRYPTED_ATTRIBUTES,
        ['gender', 'metadata'],
    ) as $attribute) {
        $expected = $values[$attribute];

        expect($raw->{$attribute})
            ->not->toBe($expected)
            ->and(Crypt::decryptString((string) $raw->{$attribute}))
            ->toBe($expected);
    }

    expect($raw->gender)
        ->not->toBe(UserGender::FEMALE->value)
        ->and(Crypt::decryptString((string) $raw->gender))
        ->toBe(UserGender::FEMALE->value)
        ->and(json_decode(
            Crypt::decryptString((string) $raw->metadata),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))
        ->toBe($values['metadata'])
        ->and(Crypt::decryptString((string) $raw->locale))
        ->toBe('vi')
        ->and(Crypt::decryptString((string) $raw->timezone))
        ->toBe('Asia/Ho_Chi_Minh');

    $reloaded = $profile->fresh();

    expect($reloaded->birthdate)
        ->toBeInstanceOf(Carbon::class)
        ->and($reloaded->birthdate->toDateString())
        ->toBe('1994-03-21')
        ->and($reloaded->gender)
        ->toBe(UserGender::FEMALE)
        ->and($reloaded->metadata)
        ->toBe($values['metadata'])
        ->and(array_intersect(
            UserProfile::ENCRYPTED_ATTRIBUTES,
            array_keys($reloaded->toArray()),
        ))
        ->toBe([]);
});

it('preserves nullable protected values as database null', function (): void {
    $profile = User::factory()->create()->profile()->firstOrFail();
    $nullableAttributes = array_values(array_diff(
        UserProfile::ENCRYPTED_ATTRIBUTES,
        ['first_name', 'last_name', 'gender'],
    ));

    $profile->update(array_fill_keys($nullableAttributes, null));
    $raw = DB::table('user_profiles')->where('uuid', $profile->getKey())->firstOrFail();
    $reloaded = $profile->fresh();

    foreach ($nullableAttributes as $attribute) {
        expect($raw->{$attribute})
            ->toBeNull()
            ->and($reloaded->getAttribute($attribute))
            ->toBeNull();
    }
});

it('uses randomized ciphertext for identical profile values', function (): void {
    $first = User::factory()->create()->profile()->firstOrFail();
    $second = User::factory()->create()->profile()->firstOrFail();

    $first->update(['first_name' => 'Identical', 'last_name' => 'Person']);
    $second->update(['first_name' => 'Identical', 'last_name' => 'Person']);

    $firstRaw = DB::table('user_profiles')->where('uuid', $first->getKey())->firstOrFail();
    $secondRaw = DB::table('user_profiles')->where('uuid', $second->getKey())->firstOrFail();

    expect($firstRaw->first_name)
        ->not->toBe($secondRaw->first_name)
        ->and(Crypt::decryptString((string) $firstRaw->first_name))
        ->toBe('Identical')
        ->and(Crypt::decryptString((string) $secondRaw->first_name))
        ->toBe('Identical');
});

it('encrypts the default gender for minimal profiles and accepts factory enums', function (): void {
    $factoryProfile = User::factory()->create()->profile()->firstOrFail();
    $factoryRaw = DB::table('user_profiles')
        ->where('uuid', $factoryProfile->getKey())
        ->firstOrFail();

    expect($factoryProfile->gender)
        ->toBeInstanceOf(UserGender::class)
        ->and($factoryRaw->gender)
        ->not->toBe($factoryProfile->gender->value);

    $user = User::factory()->create();
    $user->profile()->delete();
    $profile = $user->profile()->create([
        'first_name' => 'Minimal',
        'last_name' => 'Profile',
    ]);
    $raw = DB::table('user_profiles')->where('uuid', $profile->getKey())->firstOrFail();

    expect($profile->fresh()->gender)
        ->toBe(UserGender::OTHER)
        ->and(Crypt::decryptString((string) $raw->gender))
        ->toBe(UserGender::OTHER->value);
});

it('returns plaintext through the authenticated API while storing ciphertext', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['user:update']);

    $this->patchJson('/api/v1/auth/me', [
        'first_name' => 'API Name',
        'last_name' => 'Encrypted',
        'phone' => '+84987654321',
        'locale' => 'vi',
        'timezone' => 'Asia/Ho_Chi_Minh',
    ])
        ->assertOk()
        ->assertJsonPath('data.profile.first_name', 'API Name')
        ->assertJsonPath('data.profile.last_name', 'Encrypted')
        ->assertJsonPath('data.profile.phone', '+84987654321');

    $raw = DB::table('user_profiles')
        ->where('user_uuid', $user->getKey())
        ->firstOrFail();

    expect($raw->first_name)
        ->not->toBe('API Name')
        ->and(Crypt::decryptString((string) $raw->first_name))
        ->toBe('API Name')
        ->and($raw->phone)
        ->not->toBe('+84987654321')
        ->and($raw->locale)
        ->not->toBe('vi')
        ->and($raw->timezone)
        ->not->toBe('Asia/Ho_Chi_Minh');
});

it('backfills legacy plaintext through the shadow-column migration', function (): void {
    $user = User::factory()->create();
    recreateLegacyUserProfilesTable();

    $profileUuid = (string) Uuid::uuid7();
    $values = protectedProfileValues();

    DB::table('user_profiles')->insert([
        'uuid' => $profileUuid,
        'user_uuid' => $user->getKey(),
        ...$values,
        'gender' => UserGender::FEMALE->value,
        'metadata' => json_encode(
            $values['metadata'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Simulate an interrupted prior attempt: one column already reached the
    // detached-shadow phase and another has stale shadow data.
    Schema::table('user_profiles', function (Blueprint $table): void {
        $table->text('first_name_encrypted')->nullable();
        $table->text('last_name_encrypted')->nullable();
    });
    DB::table('user_profiles')->where('uuid', $profileUuid)->update([
        'first_name_encrypted' => Crypt::encryptString($values['first_name']),
        'last_name_encrypted' => Crypt::encryptString('stale value'),
    ]);
    Schema::table('user_profiles', function (Blueprint $table): void {
        $table->dropColumn('first_name');
    });

    $migration = require database_path(
        'migrations/2026_07_27_000021_encrypt_user_profile_personal_data.php',
    );
    $migration->up();

    $raw = DB::table('user_profiles')->where('uuid', $profileUuid)->firstOrFail();
    $profile = UserProfile::query()->findOrFail($profileUuid);

    expect($raw->first_name)
        ->not->toBe($values['first_name'])
        ->and(Crypt::decryptString((string) $raw->first_name))
        ->toBe($values['first_name'])
        ->and($profile->first_name)
        ->toBe($values['first_name'])
        ->and($profile->birthdate->toDateString())
        ->toBe($values['birthdate'])
        ->and($profile->gender)
        ->toBe(UserGender::FEMALE)
        ->and($profile->metadata)
        ->toBe($values['metadata']);

    $user->delete();

    expect(DB::table('user_profiles')->where('uuid', $profileUuid)->exists())
        ->toBeFalse();
});

it('rotates profile ciphertext from a previous key to the current key', function (): void {
    /** @var Encrypter $originalEncrypter */
    $originalEncrypter = Crypt::getFacadeRoot();
    $cipher = (string) config('app.cipher');
    $oldKey = Encrypter::generateKey($cipher);
    $newKey = Encrypter::generateKey($cipher);

    try {
        $oldEncrypter = new Encrypter($oldKey, $cipher);
        Crypt::swap($oldEncrypter);

        $profile = User::factory()->create()->profile()->firstOrFail();
        $before = DB::table('user_profiles')
            ->where('uuid', $profile->getKey())
            ->value('first_name');
        $plaintext = $profile->first_name;

        $newEncrypter = new Encrypter($newKey, $cipher);
        $newEncrypter->previousKeys([$oldKey]);
        Crypt::swap($newEncrypter);

        expect($newEncrypter->decryptString((string) $before))
            ->toBe($plaintext);

        $this->artisan('app:user-profiles-reencrypt', ['--force' => true])
            ->assertSuccessful();

        $after = DB::table('user_profiles')
            ->where('uuid', $profile->getKey())
            ->value('first_name');
        $currentKeyOnly = new Encrypter($newKey, $cipher);

        expect($after)
            ->not->toBe($before)
            ->and($currentKeyOnly->decryptString((string) $after))
            ->toBe($plaintext)
            ->and(fn () => $oldEncrypter->decryptString((string) $after))
            ->toThrow(DecryptException::class);
    } finally {
        Crypt::swap($originalEncrypter);
    }
});

it('rejects tampered encrypted profile data', function (): void {
    $profile = User::factory()->create()->profile()->firstOrFail();

    DB::table('user_profiles')
        ->where('uuid', $profile->getKey())
        ->update(['first_name' => 'tampered-ciphertext']);

    expect(fn () => $profile->fresh()->first_name)
        ->toThrow(DecryptException::class);
});

it('rejects tampered encrypted date enum and metadata values', function (): void {
    foreach (['birthdate', 'gender', 'metadata'] as $attribute) {
        $profile = User::factory()->create()->profile()->firstOrFail();

        DB::table('user_profiles')
            ->where('uuid', $profile->getKey())
            ->update([$attribute => 'tampered-ciphertext']);

        expect(fn () => $profile->fresh()->getAttribute($attribute))
            ->toThrow(DecryptException::class);
    }
});

it('redacts every protected profile key from activity properties', function (): void {
    $properties = [
        ...array_fill_keys(UserProfile::ENCRYPTED_ATTRIBUTES, 'private'),
        'user_uuid' => 'technical-identifier',
    ];
    $sanitized = ActivityLogSanitizer::sanitize($properties);

    foreach (UserProfile::ENCRYPTED_ATTRIBUTES as $attribute) {
        expect($sanitized[$attribute])->toBe('[REDACTED]');
    }

    expect($sanitized['user_uuid'])->toBe('technical-identifier');
});
