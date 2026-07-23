<?php

use App\Models\LoginSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('app:starter-kit-install --force')->assertSuccessful();
});

it('redirects guests to the filament login for both documentation routes', function () {
    $this->get('/admin/api-docs')->assertRedirect('/admin/login');
    $this->get('/admin/api-docs/openapi.json')->assertRedirect('/admin/login');
});

it('denies a user who has panel permission but no admin role', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('panel.access');

    $this->actingAs($user)->get('/admin/api-docs')->assertForbidden();
    $this->get('/admin/api-docs/openapi.json')->assertForbidden();
});

it('denies an admin role when panel access permission is missing', function () {
    Role::findByName('admin')->revokePermissionTo('panel.access');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/api-docs')->assertForbidden();
    $this->get('/admin/api-docs/openapi.json')->assertForbidden();
});

it('serves the ui and openapi specification to an admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/admin/api-docs')
        ->assertOk()
        ->assertSee('Douwyn API Documentation');

    $specification = $this->getJson('/admin/api-docs/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('components.securitySchemes.http.scheme', 'bearer')
        ->assertJsonPath('components.securitySchemes.sanctumCookie.in', 'cookie')
        ->assertJsonStructure([
            'paths' => [
                '/auth/register',
                '/auth/login',
                '/auth/token/refresh',
                '/auth/session/login',
                '/auth/email/verify',
                '/auth/password/forgot',
                '/auth/password/reset',
                '/auth/me',
                '/auth/devices',
                '/account/email',
                '/account/email/verification-notification',
                '/account/email/change',
                '/account/email/change/confirm',
                '/account/security',
                '/account/security/sessions',
                '/account/security/sessions/others',
                '/account/security/sessions/{session}',
                '/account/security/two-factor/app/setup',
                '/account/security/two-factor/app/confirm',
                '/account/security/two-factor/email/setup',
                '/account/security/two-factor/email/resend',
                '/account/security/two-factor/email/confirm',
                '/account/security/two-factor/email/current-code',
                '/account/security/two-factor',
                '/account/security/recovery-codes/regenerate',
            ],
        ]);

    $document = $specification->json();
    $responseSchema = function (string $path, string $method, int $status) use ($document): array {
        return $document['paths'][$path][$method]['responses'][(string) $status]['content']['application/json']['schema'];
    };

    $setupSchema = $responseSchema('/account/security/two-factor/app/setup', 'post', 201);
    $emailSetupSchema = $responseSchema('/account/security/two-factor/email/setup', 'post', 201);
    $confirmationSchema = $responseSchema('/account/security/two-factor/app/confirm', 'post', 200);
    $sessionsSchema = $responseSchema('/account/security/sessions', 'get', 200);
    $sessionResourceSchema = $document['components']['schemas']['LoginSessionResource'];
    $sessionSchema = $document['components']['schemas']['LoginSessionData'];
    $singleRevokeSchema = $responseSchema('/account/security/sessions/{session}', 'delete', 200);
    $bulkRevokeSchema = $responseSchema('/account/security/sessions/others', 'delete', 200);
    $mobileTokenSchema = $document['components']['schemas']['MobileTokenPairResource'];
    $deviceSchema = $document['components']['schemas']['ApiDeviceSessionData'];
    $emailStatusSchema = $responseSchema('/account/email', 'get', 200);
    $resetRequestSchema = $document['components']['schemas']['ResetPasswordRequest'];
    $emailChangeRequestSchema = $document['components']['schemas']['RequestEmailChangeRequest'];

    expect(data_get($setupSchema, 'properties.data.type'))->toBe('object')
        ->and(data_get($setupSchema, 'properties.data.properties.setup_token.type'))->toBe('string')
        ->and(data_get($setupSchema, 'properties.data.properties.method.const'))->toBe('app')
        ->and(data_get($setupSchema, 'properties.data.required'))->toContain('secret', 'otpauth_uri')
        ->and(data_get($setupSchema, 'properties.data.properties.expires_at.format'))->toBe('date-time')
        ->and(data_get($emailSetupSchema, 'properties.data.properties.method.const'))->toBe('email')
        ->and(data_get($emailSetupSchema, 'properties.data.properties.secret'))->toBeNull()
        ->and(data_get($confirmationSchema, 'properties.data.properties.recovery_codes.type'))->toBe('array')
        ->and(data_get($confirmationSchema, 'properties.data.properties.recovery_codes.items.type'))->toBe('string')
        ->and(data_get($sessionsSchema, 'properties.data.type'))->toBe('array')
        ->and(data_get($sessionsSchema, 'properties.links.type'))->toBe('object')
        ->and(data_get($sessionsSchema, 'properties.meta.type'))->toBe('object')
        ->and(data_get($sessionsSchema, 'properties.context.properties.credential_type.enum'))->toBe(['token', 'session'])
        ->and($sessionResourceSchema['$ref'])->toBe('#/components/schemas/LoginSessionData')
        ->and(data_get($sessionSchema, 'properties.current.type'))->toBe('boolean')
        ->and(data_get($sessionSchema, 'properties.expires_at.format'))->toBe('date-time')
        ->and(data_get($document, 'components.schemas.LoginSessionStatus.enum'))->toBe(['active'])
        ->and(data_get($singleRevokeSchema, 'properties.data.properties.current_session_revoked.type'))->toBe('boolean')
        ->and(data_get($bulkRevokeSchema, 'properties.data.properties.revoked_count.type'))->toBe('integer')
        ->and(data_get($bulkRevokeSchema, 'properties.data.properties.current_session_revoked.type'))->toBe('boolean')
        ->and(data_get($mobileTokenSchema, 'properties.expires_in.type'))->toBe('integer')
        ->and(data_get($mobileTokenSchema, 'properties.device_session_id.type'))->toBe('string')
        ->and(data_get($mobileTokenSchema, 'properties.expires_at.format'))->toBe('date-time')
        ->and(data_get($deviceSchema, 'properties.platform.$ref'))->toBe('#/components/schemas/DevicePlatform')
        ->and(data_get($deviceSchema, 'properties.app_version.type'))->toBe(['string', 'null'])
        ->and(data_get($deviceSchema, 'properties.last_seen_at.format'))->toBe('date-time')
        ->and(data_get($deviceSchema, 'properties.refresh_expires_at.type'))->toBe('string')
        ->and(data_get($deviceSchema, 'properties.refresh_expires_at.format'))->toBe('date-time')
        ->and(data_get($document, 'components.schemas.ApiDeviceSessionStatus.enum'))
        ->toBe(['active', 'expired', 'revoked', 'compromised'])
        ->and(data_get($emailStatusSchema, 'properties.data.properties.verified.type'))->toBe('boolean')
        ->and(data_get($emailStatusSchema, 'properties.data.properties.pending_email.type'))->toBe(['string', 'null'])
        ->and(data_get($emailStatusSchema, 'properties.data.properties.pending_expires_at.format'))->toBe('date-time')
        ->and(data_get($resetRequestSchema, 'required'))->toContain('token', 'password', 'password_confirmation')
        ->and(data_get($emailChangeRequestSchema, 'required'))->toContain('email', 'current_password')
        ->and($document['paths']['/auth/email/verify']['post']['security'])->toBe([])
        ->and($document['paths']['/auth/password/forgot']['post']['security'])->toBe([])
        ->and($document['paths']['/auth/password/reset']['post']['security'])->toBe([]);

    foreach ([
        ['/account/security/two-factor/app/setup', 'post'],
        ['/account/security/two-factor/app/confirm', 'post'],
        ['/account/security/sessions', 'get'],
        ['/account/security/sessions/others', 'delete'],
        ['/account/security/sessions/{session}', 'delete'],
        ['/account/email', 'get'],
        ['/account/email/change', 'post'],
        ['/account/email/change/confirm', 'post'],
    ] as [$path, $method]) {
        expect($document['paths'][$path][$method]['responses'])->toHaveKey('403');
    }

    expect($document['paths']['/auth/token/refresh']['post']['responses'])
        ->toHaveKeys(['200', '401', '403', '409', '422', '429']);

    $security = collect($specification->json('security'));

    expect($security->contains(fn (array $requirement): bool => array_key_exists('http', $requirement)))->toBeTrue()
        ->and($security->contains(fn (array $requirement): bool => array_key_exists('sanctumCookie', $requirement)))->toBeTrue();
});

it('serves documentation to an active super admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)->get('/admin/api-docs')->assertOk();
    $this->get('/admin/api-docs/openapi.json')->assertOk();
});

it('denies an inactive admin', function () {
    $admin = User::factory()->create(['is_inactive' => true]);
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/api-docs')->assertForbidden();
    $this->get('/admin/api-docs/openapi.json')->assertForbidden();
});

it('denies api documentation after the browser session is revoked', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/api-docs')->assertOk();

    $session = LoginSession::query()->where('user_uuid', $admin->uuid)->firstOrFail();
    $session->revoke();

    $this->get('/admin/api-docs/openapi.json')->assertRedirect('/admin/login');
    $this->assertGuest();
});
