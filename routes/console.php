<?php

use App\Models\AccountActionToken;
use App\Models\ApiDeviceSession;
use App\Models\AuthChallenge;
use App\Models\LoginSession;
use App\Models\RefreshToken;
use App\Models\TwoFactorCode;
use App\Models\TwoFactorSetup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => LoginSession::query()
    ->where(fn ($query) => $query
        ->where('last_active_at', '<', now()->subDays(30))
        ->orWhere('revoked_at', '<', now()->subDays(7)))
    ->delete())
    ->daily()
    ->name('prune-login-sessions')
    ->withoutOverlapping();

Schedule::call(fn () => TwoFactorCode::query()
    ->where('expires_at', '<', now()->subDay())
    ->delete())
    ->daily()
    ->name('prune-two-factor-codes')
    ->withoutOverlapping();

Schedule::call(function (): void {
    AuthChallenge::query()
        ->where(fn ($query) => $query
            ->where('expires_at', '<', now()->subDay())
            ->orWhere('consumed_at', '<', now()->subDay()))
        ->delete();

    TwoFactorSetup::query()
        ->where(fn ($query) => $query
            ->where('expires_at', '<', now()->subDay())
            ->orWhere('consumed_at', '<', now()->subDay()))
        ->delete();

    AccountActionToken::query()
        ->where(fn ($query) => $query
            ->where('expires_at', '<', now()->subDay())
            ->orWhere('consumed_at', '<', now()->subDay()))
        ->delete();
})
    ->daily()
    ->name('prune-auth-security-challenges')
    ->withoutOverlapping();

Schedule::call(fn () => RefreshToken::query()
    ->whereNotNull('rotation_response')
    ->where('rotation_expires_at', '<', now())
    ->update([
        'rotation_request_hash' => null,
        'rotation_device_hash' => null,
        'rotation_response' => null,
        'rotation_expires_at' => null,
        'updated_at' => now(),
    ]))
    ->everyMinute()
    ->name('prune-mobile-refresh-replays')
    ->withoutOverlapping();

Schedule::call(function (): void {
    ApiDeviceSession::query()
        ->where(fn ($query) => $query
            ->where('revoked_at', '<', now()->subDays(30))
            ->orWhere('refresh_expires_at', '<', now()->subDays(30))
            ->orWhere('absolute_expires_at', '<', now()->subDays(30)))
        ->delete();
})
    ->daily()
    ->name('prune-mobile-auth-sessions')
    ->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->withoutOverlapping();

Schedule::command('activitylog:clean --force')
    ->weekly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();
