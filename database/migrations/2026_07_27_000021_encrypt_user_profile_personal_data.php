<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avoid retaining PostgreSQL DDL locks for the complete chunked backfill.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * @var array<string, 'longText'|'text'>
     */
    private const array PROTECTED_COLUMNS = [
        'first_name' => 'text',
        'last_name' => 'text',
        'birthdate' => 'text',
        'gender' => 'text',
        'phone' => 'text',
        'country' => 'text',
        'city' => 'text',
        'address_line1' => 'text',
        'address_line2' => 'text',
        'postal_code' => 'text',
        'avatar_url' => 'text',
        'bio' => 'longText',
        'website' => 'text',
        'locale' => 'text',
        'timezone' => 'text',
        'metadata' => 'longText',
    ];

    /**
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = [
        'first_name',
        'last_name',
        'gender',
    ];

    public function up(): void
    {
        $this->assertRecoverableSchema();
        $this->addShadowColumns();
        $this->backfillEncryptedValues();
        $this->verifyEncryptedValues();
        $this->verifyDetachedShadowColumns();
        $this->replacePlaintextColumns();

        // These fields were non-null before encryption. Gender is now supplied
        // by the model instead of an unsafe plaintext database default.
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->text('first_name')->nullable(false)->change();
            $table->text('last_name')->nullable(false)->change();
            $table->text('gender')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'User profile encryption is intentionally one-way. Restore a reviewed encrypted backup instead of writing personal data back to plaintext.',
        );
    }

    private function addShadowColumns(): void
    {
        $missingColumns = array_filter(
            self::PROTECTED_COLUMNS,
            fn (string $type, string $column): bool => ! Schema::hasColumn(
                'user_profiles',
                $this->shadowColumn($column),
            ),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missingColumns === []) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) use ($missingColumns): void {
            foreach ($missingColumns as $column => $type) {
                $shadow = $this->shadowColumn($column);

                if ($type === 'longText') {
                    $table->longText($shadow)->nullable();

                    continue;
                }

                $table->text($shadow)->nullable();
            }
        });
    }

    private function backfillEncryptedValues(): void
    {
        $protectedColumns = $this->columnsWithOriginalAndShadow();

        if ($protectedColumns === []) {
            return;
        }

        $columns = [
            'uuid',
            ...$protectedColumns,
            ...array_map(
                fn (string $column): string => $this->shadowColumn($column),
                $protectedColumns,
            ),
        ];

        DB::table('user_profiles')
            ->select($columns)
            ->chunkById(200, function ($profiles) use ($protectedColumns): void {
                foreach ($profiles as $profile) {
                    $updates = [];

                    foreach ($protectedColumns as $column) {
                        $shadow = $this->shadowColumn($column);
                        $value = $profile->{$column};

                        if ($value === null) {
                            if ($profile->{$shadow} !== null) {
                                $updates[$shadow] = null;
                            }

                            continue;
                        }

                        // The original remains the source of truth until the
                        // contract phase. Rewriting an existing shadow makes
                        // an interrupted backfill resumable and prevents stale
                        // shadow data from winning on the next attempt.
                        $updates[$shadow] = $this->encryptStoredValue($value);
                    }

                    if ($updates !== []) {
                        DB::table('user_profiles')
                            ->where('uuid', $profile->uuid)
                            ->update($updates);
                    }
                }
            }, 'uuid');
    }

    private function verifyEncryptedValues(): void
    {
        $protectedColumns = $this->columnsWithOriginalAndShadow();

        if ($protectedColumns === []) {
            return;
        }

        $columns = [
            'uuid',
            ...$protectedColumns,
            ...array_map(
                fn (string $column): string => $this->shadowColumn($column),
                $protectedColumns,
            ),
        ];

        DB::table('user_profiles')
            ->select($columns)
            ->chunkById(200, function ($profiles) use ($protectedColumns): void {
                foreach ($profiles as $profile) {
                    foreach ($protectedColumns as $column) {
                        $shadow = $this->shadowColumn($column);
                        $value = $profile->{$column};
                        $encrypted = $profile->{$shadow};

                        if ($value === null && $encrypted === null) {
                            continue;
                        }

                        if ($value === null || $encrypted === null) {
                            throw new RuntimeException(
                                "Encrypted profile backfill is incomplete for [{$column}] on profile [{$profile->uuid}].",
                            );
                        }

                        $this->assertEncryptedValueMatches(
                            $column,
                            (string) $profile->uuid,
                            $value,
                            $encrypted,
                        );
                    }
                }
            }, 'uuid');
    }

    private function verifyDetachedShadowColumns(): void
    {
        $detachedColumns = array_values(array_filter(
            array_keys(self::PROTECTED_COLUMNS),
            fn (string $column): bool => ! Schema::hasColumn('user_profiles', $column)
                && Schema::hasColumn('user_profiles', $this->shadowColumn($column)),
        ));

        if ($detachedColumns === []) {
            return;
        }

        $columns = [
            'uuid',
            ...array_map(
                fn (string $column): string => $this->shadowColumn($column),
                $detachedColumns,
            ),
        ];

        DB::table('user_profiles')
            ->select($columns)
            ->chunkById(200, function ($profiles) use ($detachedColumns): void {
                foreach ($profiles as $profile) {
                    foreach ($detachedColumns as $column) {
                        $encrypted = $profile->{$this->shadowColumn($column)};

                        if ($encrypted === null) {
                            if (in_array($column, self::REQUIRED_COLUMNS, true)) {
                                throw new RuntimeException(
                                    "Required encrypted profile value [{$column}] is missing on profile [{$profile->uuid}].",
                                );
                            }

                            continue;
                        }

                        Crypt::decryptString($this->stringValue($encrypted));
                    }
                }
            }, 'uuid');
    }

    private function replacePlaintextColumns(): void
    {
        foreach (array_keys(self::PROTECTED_COLUMNS) as $column) {
            $shadow = $this->shadowColumn($column);

            if (
                Schema::hasColumn('user_profiles', $column)
                && Schema::hasColumn('user_profiles', $shadow)
            ) {
                Schema::table(
                    'user_profiles',
                    function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    },
                );
            }

            if (
                ! Schema::hasColumn('user_profiles', $column)
                && Schema::hasColumn('user_profiles', $shadow)
            ) {
                Schema::table(
                    'user_profiles',
                    function (Blueprint $table) use ($column, $shadow): void {
                        $table->renameColumn($shadow, $column);
                    },
                );
            }

            if (! Schema::hasColumn('user_profiles', $column)) {
                throw new RuntimeException(
                    "Encrypted profile column [{$column}] could not be restored after migration.",
                );
            }
        }
    }

    private function assertRecoverableSchema(): void
    {
        if (! Schema::hasTable('user_profiles')) {
            throw new RuntimeException(
                'The user_profiles table must exist before personal data can be encrypted.',
            );
        }

        foreach (array_keys(self::PROTECTED_COLUMNS) as $column) {
            if (
                ! Schema::hasColumn('user_profiles', $column)
                && ! Schema::hasColumn('user_profiles', $this->shadowColumn($column))
            ) {
                throw new RuntimeException(
                    "Neither [{$column}] nor its encrypted shadow exists in user_profiles.",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function columnsWithOriginalAndShadow(): array
    {
        return array_values(array_filter(
            array_keys(self::PROTECTED_COLUMNS),
            fn (string $column): bool => Schema::hasColumn('user_profiles', $column)
                && Schema::hasColumn('user_profiles', $this->shadowColumn($column)),
        ));
    }

    private function encryptStoredValue(mixed $value): string
    {
        $stored = $this->stringValue($value);

        if (Encrypter::appearsEncrypted($stored)) {
            // Validate the payload before accepting it. A malformed encrypted
            // value must stop the migration. Re-encrypt it so ciphertext read
            // through APP_PREVIOUS_KEYS is always moved to the current key.
            $stored = Crypt::decryptString($stored);
        }

        return Crypt::encryptString($stored);
    }

    private function assertEncryptedValueMatches(
        string $column,
        string $profileUuid,
        mixed $original,
        mixed $encrypted,
    ): void {
        $expected = $this->stringValue($original);

        if (Encrypter::appearsEncrypted($expected)) {
            $expected = Crypt::decryptString($expected);
        }

        $actual = Crypt::decryptString($this->stringValue($encrypted));

        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException(
                "Encrypted profile verification failed for [{$column}] on profile [{$profileUuid}].",
            );
        }
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function shadowColumn(string $column): string
    {
        return "{$column}_encrypted";
    }
};
