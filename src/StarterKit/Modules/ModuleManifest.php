<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Modules;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    public string $package;

    public string $version;

    public string $requiresPlatform;

    public function __construct(string $package, string $version, string $requiresPlatform)
    {
        $package = trim($package);
        $version = trim($version);
        $requiresPlatform = trim($requiresPlatform);

        if (! preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/D', $package)) {
            throw new InvalidArgumentException("Invalid Composer package name [{$package}].");
        }

        if ($version === '') {
            throw new InvalidArgumentException("Module [{$package}] must declare its version.");
        }

        if ($requiresPlatform === '') {
            throw new InvalidArgumentException("Module [{$package}] must declare a platform constraint.");
        }

        $this->package = $package;
        $this->version = $version;
        $this->requiresPlatform = $requiresPlatform;
    }

    /** @return array{package: string, version: string, requires_platform: string} */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'requires_platform' => $this->requiresPlatform,
        ];
    }
}
