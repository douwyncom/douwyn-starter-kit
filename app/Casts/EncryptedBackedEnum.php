<?php

declare(strict_types=1);

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<BackedEnum, BackedEnum|int|string>
 */
final readonly class EncryptedBackedEnum implements CastsAttributes, ComparesCastableAttributes
{
    /** @var class-string<BackedEnum> */
    private string $enumClass;

    public function __construct(string $enumClass)
    {
        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException(
                "The encrypted enum cast requires a backed enum; [$enumClass] given.",
            );
        }

        /** @var class-string<BackedEnum> $enumClass */
        $this->enumClass = $enumClass;
    }

    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?BackedEnum {
        if ($value === null) {
            return null;
        }

        $enumClass = $this->enumClass;

        return $enumClass::from(Crypt::decryptString((string) $value));
    }

    public function set(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $enumClass = $this->enumClass;
        $enum = $value instanceof BackedEnum
            ? $value
            : $enumClass::from($value);

        if (! $enum instanceof $enumClass) {
            throw new InvalidArgumentException(
                "The [$key] attribute must be an instance of [$enumClass].",
            );
        }

        return Crypt::encryptString((string) $enum->value);
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
