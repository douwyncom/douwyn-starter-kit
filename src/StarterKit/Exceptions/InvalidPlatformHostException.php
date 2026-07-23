<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Exceptions;

use Douwyn\StarterKit\Platform;
use RuntimeException;

final class InvalidPlatformHostException extends RuntimeException
{
    public static function forPackage(?string $actual): self
    {
        return new self(sprintf(
            'Douwyn Starter Kit modules require root package [%s]; [%s] is running.',
            Platform::ROOT_PACKAGE,
            $actual ?? 'unknown',
        ));
    }
}
