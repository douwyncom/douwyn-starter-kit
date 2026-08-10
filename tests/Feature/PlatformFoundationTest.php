<?php

declare(strict_types=1);

use Douwyn\StarterKit\Contracts\PrivateStorageResolver;
use Douwyn\StarterKit\Platform;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

it('localizes a public API route without requiring authentication', function () {
    Route::middleware(['api', Platform::API_LOCALIZED_MIDDLEWARE])
        ->get('api/_platform/localized', static fn () => response()->json([
            'authenticated' => auth('sanctum')->check(),
            'locale' => app()->getLocale(),
        ]));

    $this->withHeader('Accept-Language', 'vi-VN,vi;q=0.9')
        ->getJson('/api/_platform/localized')
        ->assertOk()
        ->assertJson([
            'authenticated' => false,
            'locale' => 'vi',
        ]);
});

it('resolves the configured public media disk through the platform contract', function () {
    config([Platform::MEDIA_DISK_CONFIG => 's3']);

    expect(Platform::mediaDisk())->toBe('s3');

    config([Platform::MEDIA_DISK_CONFIG => '  ']);

    expect(Platform::mediaDisk())->toBe(Platform::LOCAL_PUBLIC_MEDIA_DISK);
});

it('resolves the configured private disk through the platform contract', function () {
    config([Platform::PRIVATE_DISK_CONFIG => 's3']);

    expect(Platform::privateDisk())->toBe('s3');

    config([Platform::PRIVATE_DISK_CONFIG => '  ']);

    expect(Platform::privateDisk())->toBe(Platform::LOCAL_PRIVATE_DISK);
});

it('resolves only private local disks through the private-storage contract', function () {
    config([
        Platform::PRIVATE_DISK_CONFIG => 'local',
        Platform::MEDIA_DISK_CONFIG => 'public',
    ]);

    $resolver = app(PrivateStorageResolver::class);

    expect($resolver->resolve())->toBe('local')
        ->and(fn () => $resolver->resolve('public'))
        ->toThrow(LogicException::class, 'cannot be used for private files');

    config([
        Platform::PRIVATE_DISK_CONFIG => '  ',
        'filesystems.default' => 'local',
    ]);

    expect($resolver->resolve())->toBe('local');

    config([
        'filesystems.disks.unsafe-local' => [
            'driver' => 'local',
            'root' => public_path('uploads'),
        ],
    ]);

    expect(fn () => $resolver->resolve('unsafe-local'))
        ->toThrow(LogicException::class, 'inside a public directory');
});

it('allows a private S3 disk that is also the configured media disk', function () {
    config([
        'filesystems.default' => 's3',
        Platform::PRIVATE_DISK_CONFIG => 's3',
        Platform::MEDIA_DISK_CONFIG => 's3',
        'filesystems.disks.s3.visibility' => 'private',
    ]);

    expect(app(PrivateStorageResolver::class)->resolve())->toBe('s3');
});

it('rejects an existing local root symlinked into a public directory', function () {
    $link = sys_get_temp_dir().'/starter-kit-private-resolver-'.Str::uuid();

    if (! symlink(public_path(), $link)) {
        $this->markTestSkipped('The operating system did not permit a symlink.');
    }

    try {
        config([
            'filesystems.disks.unsafe-symlink' => [
                'driver' => 'local',
                'root' => $link,
            ],
        ]);

        expect(fn () => app(PrivateStorageResolver::class)->resolve('unsafe-symlink'))
            ->toThrow(LogicException::class, 'inside a public directory');
    } finally {
        unlink($link);
    }
});

it('rejects a missing local root below a symlink into a public directory', function () {
    $link = sys_get_temp_dir().'/starter-kit-private-parent-'.Str::uuid();

    if (! symlink(public_path(), $link)) {
        $this->markTestSkipped('The operating system did not permit a symlink.');
    }

    try {
        config([
            'filesystems.disks.unsafe-symlink-parent' => [
                'driver' => 'local',
                'root' => $link.'/not-created-yet',
            ],
        ]);

        expect(fn () => app(PrivateStorageResolver::class)->resolve('unsafe-symlink-parent'))
            ->toThrow(LogicException::class, 'inside a public directory');
    } finally {
        unlink($link);
    }
});

it('supports local-public and S3-compatible media adapters', function () {
    config([Platform::MEDIA_DISK_CONFIG => 'public']);
    Storage::forgetDisk('public');

    expect(Storage::disk(Platform::mediaDisk())->getAdapter())
        ->toBeInstanceOf(LocalFilesystemAdapter::class);

    config([
        Platform::MEDIA_DISK_CONFIG => 's3',
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:9000',
        'filesystems.disks.s3.use_path_style_endpoint' => true,
    ]);
    Storage::forgetDisk('s3');

    expect(Storage::disk(Platform::mediaDisk())->getAdapter())
        ->toBeInstanceOf(AwsS3V3Adapter::class);
});
