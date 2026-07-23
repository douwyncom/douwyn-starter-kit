<?php

declare(strict_types=1);

use App\Data\Auth\MobileDeviceData;
use App\Enums\DevicePlatform;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\DeviceFingerprint;
use App\Services\Auth\MobileTokenService;
use App\Services\Auth\TokenIssuer;
use App\Support\Settings;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Douwyn\StarterKit\Auth\TokenAbilityProfile;
use Douwyn\StarterKit\Auth\TokenAbilityRegistry;
use Douwyn\StarterKit\Contracts\LocaleResolver;
use Douwyn\StarterKit\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(SetApiLocale::class)->get(
        'api/v1/_platform-locale',
        fn () => response()->json(['locale' => app()->getLocale()]),
    );
    Route::middleware(Platform::API_AUTHENTICATED_MIDDLEWARE)->get(
        'api/v1/_platform-authenticated-locale',
        fn () => response()->json(['locale' => app()->getLocale()]),
    );
    Route::middleware(['web', SetLocaleMiddleware::class])->get(
        '_platform-web-locale',
        fn () => response()->json(['locale' => app()->getLocale()]),
    );
});

it('resolves api locale from profile, request, settings, then application config', function () {
    Settings::set('general', 'locale', 'vi');

    expect(Settings::get('general', 'locale'))->toBe('vi');

    $this->withHeader('Accept-Language', '')
        ->getJson('/api/v1/_platform-locale')
        ->assertOk()
        ->assertJsonPath('locale', 'vi');

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->getJson('/api/v1/_platform-locale')
        ->assertOk()
        ->assertJsonPath('locale', 'en');

    $user = User::factory()->create();
    $user->profile()->update(['locale' => 'vi']);
    $token = $user->createToken('Locale test', ['user:read'])->plainTextToken;

    $this->withToken($token)
        ->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/_platform-authenticated-locale')
        ->assertOk()
        ->assertJsonPath('locale', 'vi');

    expect(app(LocaleResolver::class)->supportedLocales())->toBe(['en', 'vi']);
});

it('resolves web locale from profile, session, settings, then application config', function () {
    Settings::set('general', 'locale', 'vi');

    $this->withSession(['locale' => 'en'])
        ->getJson('/_platform-web-locale')
        ->assertOk()
        ->assertJsonPath('locale', 'en');

    $user = User::factory()->create();
    $user->profile()->update(['locale' => 'vi']);

    $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->getJson('/_platform-web-locale')
        ->assertOk()
        ->assertJsonPath('locale', 'vi');
});

it('uses registry abilities for newly issued legacy and mobile tokens', function () {
    $abilities = app(TokenAbilityRegistry::class);
    $abilities->extend(TokenAbilityProfile::LEGACY, ['ledger:read']);
    $abilities->extend(TokenAbilityProfile::MOBILE, ['ledger:read']);

    $legacyUser = User::factory()->create();
    $legacy = app(TokenIssuer::class)->issue(
        $legacyUser,
        'Legacy client',
        Request::create('/api/v1/auth/login', 'POST'),
    );
    $legacyToken = PersonalAccessToken::findToken($legacy['token']);

    $deviceId = (string) Str::uuid();
    $mobileUser = User::factory()->create();
    $mobile = app(MobileTokenService::class)->issue(
        $mobileUser,
        new MobileDeviceData(
            deviceName: 'Platform test',
            deviceIdHash: app(DeviceFingerprint::class)->hash($deviceId),
            platform: DevicePlatform::IOS,
            appVersion: '1.1.0',
            ipAddress: '203.0.113.10',
            userAgent: 'PlatformTest/1.1',
        ),
    );
    $mobileToken = PersonalAccessToken::findToken($mobile->accessToken);

    expect($legacyToken?->abilities)->toBe([
        'user:read',
        'user:update',
        'ledger:read',
    ])->and($mobile->deviceSession->abilities)->toBe([
        'user:read',
        'user:update',
        'devices:read',
        'devices:revoke',
        'ledger:read',
    ])->and($mobileToken?->abilities)->toBe($mobile->deviceSession->abilities);
});

it('exposes module error codes through api metadata', function () {
    app(ApiErrorCodeRegistry::class)->register(
        'insufficient_funds',
        409,
        'The account has insufficient available funds.',
    );

    $this->getJson('/api/v1/meta/error-codes')
        ->assertOk()
        ->assertJsonFragment([
            'code' => 'insufficient_funds',
            'http_status' => 409,
            'description' => 'The account has insufficient available funds.',
            'retryable' => false,
        ]);
});
