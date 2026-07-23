<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Exceptions;

use Douwyn\StarterKit\Modules\ModuleManifest;
use Douwyn\StarterKit\Platform;
use RuntimeException;

final class IncompatibleModuleException extends RuntimeException
{
    public static function forManifest(ModuleManifest $manifest): self
    {
        return new self(sprintf(
            'Module [%s] requires Douwyn Starter Kit platform [%s], but [%s] is installed.',
            $manifest->package,
            $manifest->requiresPlatform,
            Platform::VERSION,
        ));
    }

    public static function forInvalidConstraint(ModuleManifest $manifest): self
    {
        return new self(sprintf(
            'Module [%s] declares an invalid platform constraint [%s].',
            $manifest->package,
            $manifest->requiresPlatform,
        ));
    }
}
