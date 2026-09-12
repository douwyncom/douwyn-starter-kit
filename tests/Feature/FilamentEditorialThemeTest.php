<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Services\Auth\AuthSignature;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the editorial authentication shell with native theme controls', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('class="dw-auth-layout"', false)
        ->assertSee('aria-label="Douwyn"', false)
        ->assertSee('class="fi-theme-switcher"', false)
        ->assertSee('autocomplete="current-password"', false)
        ->assertSeeText('Administration workspace')
        ->assertSeeText('Secure access for authorized operators.');
});

it('renders the editorial authentication shell in Vietnamese', function (): void {
    $this->withSession(['locale' => 'vi'])
        ->get('/admin/login')
        ->assertOk()
        ->assertSeeText('Không gian quản trị')
        ->assertSeeText('Truy cập bảo mật dành cho người vận hành được ủy quyền.');
});

it('renders recovery codes with list and code semantics', function (): void {
    $html = view('filament.clusters.account.partials.recovery-codes', [
        'codes' => ['alpha-bravo', 'charlie-delta'],
        'enabled' => true,
        'method' => 'app',
        'remaining' => 2,
    ])->render();

    expect($html)
        ->toContain('<ul')
        ->toContain('aria-live="polite"')
        ->toContain('<code class="dw-recovery-code">alpha-bravo</code>')
        ->toContain('<code class="dw-recovery-code">charlie-delta</code>');
});

it('renders OTP and recovery controls without changing the authentication flow', function (): void {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::EMAIL,
        'two_factor_confirmed_at' => now(),
        'two_factor_enabled_at' => now(),
    ]);
    $user->profile->update(['locale' => 'en']);

    $this->withSession([
        'pending_user_uuid' => $user->uuid,
        'pending_otp_channel' => TwoFactorMethod::EMAIL->value,
        'pending_auth_signature' => app(AuthSignature::class)->for($user),
        'pending_started_at' => now()->timestamp,
    ]);

    Livewire::test(Login::class)
        ->assertSet('step', 'otp')
        ->assertSet('canResend', true)
        ->assertSee('Use a recovery code')
        ->assertSee('Resend code')
        ->set('otpMode', 'recovery')
        ->assertSee('Use verification code');
});
