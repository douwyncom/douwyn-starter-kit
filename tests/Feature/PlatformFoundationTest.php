<?php

declare(strict_types=1);

use Douwyn\StarterKit\Platform;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
