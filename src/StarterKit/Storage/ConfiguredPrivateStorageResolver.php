<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Storage;

use Douwyn\StarterKit\Contracts\PrivateStorageResolver;
use Douwyn\StarterKit\Platform;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;

final readonly class ConfiguredPrivateStorageResolver implements PrivateStorageResolver
{
    public function __construct(
        private Repository $config,
        private Application $application,
    ) {}

    public function resolve(?string $preferredDisk = null): string
    {
        $disk = $this->candidate($preferredDisk);
        $configuration = $this->config->get("filesystems.disks.$disk");

        if (! is_array($configuration)
            || ! is_string($configuration['driver'] ?? null)
            || trim($configuration['driver']) === '') {
            throw new LogicException(sprintf(
                'The private filesystem disk [%s] is not configured.',
                $disk,
            ));
        }

        if ($disk === Platform::LOCAL_PUBLIC_MEDIA_DISK) {
            throw new LogicException(sprintf(
                'The public filesystem disk [%s] cannot be used for private files.',
                $disk,
            ));
        }

        if (($configuration['visibility'] ?? null) === 'public') {
            throw new LogicException(sprintf(
                'The private filesystem disk [%s] declares public visibility.',
                $disk,
            ));
        }

        if ($configuration['driver'] === 'local') {
            $this->assertPrivateLocalRoot($disk, $configuration);
        }

        return $disk;
    }

    private function candidate(?string $preferredDisk): string
    {
        if (is_string($preferredDisk) && trim($preferredDisk) !== '') {
            return trim($preferredDisk);
        }

        $candidate = $this->config->get(Platform::PRIVATE_DISK_CONFIG);

        if (! is_string($candidate) || trim($candidate) === '') {
            $candidate = $this->config->get(
                'filesystems.default',
                Platform::LOCAL_PRIVATE_DISK,
            );
        }

        if (! is_string($candidate) || trim($candidate) === '') {
            throw new LogicException('A private filesystem disk must be configured.');
        }

        return trim($candidate);
    }

    /** @param array<string, mixed> $configuration */
    private function assertPrivateLocalRoot(string $disk, array $configuration): void
    {
        $root = $configuration['root'] ?? null;

        if (! is_string($root) || trim($root) === '') {
            throw new LogicException(sprintf(
                'The private local filesystem disk [%s] must declare a root.',
                $disk,
            ));
        }

        $root = $this->normalizePath($root);
        $publicRoots = [
            $this->normalizePath($this->application->publicPath()),
            $this->normalizePath($this->application->storagePath('app/public')),
        ];

        foreach ($publicRoots as $publicRoot) {
            if ($root === $publicRoot || str_starts_with($root, $publicRoot.'/')) {
                throw new LogicException(sprintf(
                    'The private local filesystem disk [%s] points inside a public directory.',
                    $disk,
                ));
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (! str_starts_with($path, '/')
            && ! preg_match('/\A[A-Za-z]:\//', $path)) {
            $path = $this->application->basePath($path);
            $path = str_replace('\\', '/', $path);
        }

        $realPath = $this->realPathIncludingExistingParent($path);

        if (is_string($realPath)) {
            $path = str_replace('\\', '/', $realPath);
        }

        $prefix = str_starts_with($path, '/') ? '/' : '';
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return rtrim($prefix.implode('/', $segments), '/');
    }

    private function realPathIncludingExistingParent(string $path): ?string
    {
        $candidate = $path;
        $missingSegments = [];

        while (! file_exists($candidate) && ! is_link($candidate)) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return null;
            }

            array_unshift($missingSegments, basename($candidate));
            $candidate = $parent;
        }

        $realParent = realpath($candidate);

        if (! is_string($realParent)) {
            return null;
        }

        $realParent = rtrim(str_replace('\\', '/', $realParent), '/');

        return $missingSegments === []
            ? $realParent
            : $realParent.'/'.implode('/', $missingSegments);
    }
}
