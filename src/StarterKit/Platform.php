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
    public const VERSION = '2.2.0';

    public const ADMIN_PANEL_ID = 'admin';

    public const AUTH_GUARD = 'web';

    public const USER_KEY = 'uuid';

    public const API_PREFIX = '/api/v1';

    public const API_AUTHENTICATED_MIDDLEWARE = 'starter-kit.api-authenticated';

    /**
     * Locale-only API stack for public module endpoints such as storefront
     * catalogues. It deliberately does not authenticate the request.
     */
    public const API_LOCALIZED_MIDDLEWARE = 'starter-kit.api-localized';

    /**
     * Configuration key used by modules for publicly addressable media.
     */
    public const MEDIA_DISK_CONFIG = 'filesystems.media';

    /**
     * Configuration key used by modules for non-public application files.
     */
    public const PRIVATE_DISK_CONFIG = 'filesystems.private';

    /**
     * The local public disk used when the configured media disk is invalid.
     */
    public const LOCAL_PUBLIC_MEDIA_DISK = 'public';

    public const LOCAL_PRIVATE_DISK = 'local';

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

    public static function mediaDisk(): string
    {
        $disk = config(self::MEDIA_DISK_CONFIG, self::LOCAL_PUBLIC_MEDIA_DISK);

        return is_string($disk) && trim($disk) !== ''
            ? trim($disk)
            : self::LOCAL_PUBLIC_MEDIA_DISK;
    }

    public static function privateDisk(): string
    {
        $disk = config(self::PRIVATE_DISK_CONFIG, self::LOCAL_PRIVATE_DISK);

        return is_string($disk) && trim($disk) !== ''
            ? trim($disk)
            : self::LOCAL_PRIVATE_DISK;
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
                'panel_access' => 'permission:panel.access',
                'auth_guard' => self::AUTH_GUARD,
                'user_key' => self::USER_KEY,
                'api_prefix' => self::API_PREFIX,
                'api_authenticated_middleware' => self::API_AUTHENTICATED_MIDDLEWARE,
                'api_localized_middleware' => self::API_LOCALIZED_MIDDLEWARE,
                'media_disk_config' => self::MEDIA_DISK_CONFIG,
                'private_disk_config' => self::PRIVATE_DISK_CONFIG,
            ],
        ];
    }
}
