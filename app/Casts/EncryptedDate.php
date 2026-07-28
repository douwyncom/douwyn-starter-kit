<?php

declare(strict_types=1);

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 */
final class EncryptedDate implements CastsAttributes, ComparesCastableAttributes
{
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?Carbon {
        if ($value === null) {
            return null;
        }

        return Carbon::createFromFormat(
            'Y-m-d',
            Crypt::decryptString((string) $value),
        )->startOfDay();
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse((string) $value);

        return Crypt::encryptString($date->format('Y-m-d'));
    }

    public function compare(
        Model $model,
        string $key,
        mixed $firstValue,
        mixed $secondValue,
    ): bool {
        if ($firstValue === $secondValue) {
            return true;
        }

        if ($firstValue === null || $secondValue === null || $this->hasPreviousKeys()) {
            return false;
        }

        return hash_equals(
            Crypt::decryptString((string) $firstValue),
            Crypt::decryptString((string) $secondValue),
        );
    }

    private function hasPreviousKeys(): bool
    {
        /** @var Encrypter $encrypter */
        $encrypter = Crypt::getFacadeRoot();

        return $encrypter->getPreviousKeys() !== [];
    }
}
