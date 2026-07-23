<?php

declare(strict_types=1);

namespace Douwyn\StarterKit;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Douwyn\StarterKit\Exceptions\InvalidPlatformHostException;

final class Platform
{
    public const ROOT_PACKAGE = 'douwyncom/douwyn-starter-kit';

    public const CAPABILITY = 'douwyncom/starter-kit-platform';

    public const MODULE_PACKAGE_PREFIX = 'douwyncom/starter-kit-';

    /**
     * Version of the public starter-kit contract, not the HTTP API contract.
     */
    public const VERSION = '2.0.0';

    public const ADMIN_PANEL_ID = 'admin';

    public const AUTH_GUARD = 'web';

    public const USER_KEY = 'uuid';

    public const API_PREFIX = '/api/v1';

    public const API_AUTHENTICATED_MIDDLEWARE = 'starter-kit.api-authenticated';

    private function __construct() {}

    public static function hostPackage(): ?string
    {
        $name = InstalledVersions::getRootPackage()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function assertHost(): void
    {
        $actual = self::hostPackage();

        if ($actual !== self::ROOT_PACKAGE) {
            throw InvalidPlatformHostException::forPackage($actual);
        }
    }

    public static function supports(string $constraint): bool
    {
        return Semver::satisfies(self::VERSION, trim($constraint));
    }

    /**
     * @return array{
     *     root_package: string,
     *     capability: string,
     *     version: string,
     *     invariants: array<string, string>
     * }
     */
    public static function metadata(): array
    {
        return [
            'root_package' => self::ROOT_PACKAGE,
            'capability' => self::CAPABILITY,
            'version' => self::VERSION,
            'invariants' => [
                'php' => '^8.5',
                'laravel' => '^13.0',
                'filament' => '^5.0',
                'admin_panel' => self::ADMIN_PANEL_ID,
                'auth_guard' => self::AUTH_GUARD,
                'user_key' => self::USER_KEY,
                'api_prefix' => self::API_PREFIX,
                'api_authenticated_middleware' => self::API_AUTHENTICATED_MIDDLEWARE,
            ],
        ];
    }
}
