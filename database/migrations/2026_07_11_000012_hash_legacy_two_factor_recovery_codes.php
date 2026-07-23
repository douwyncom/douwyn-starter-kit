<?php

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('two_factor_recovery_codes')
            ->orderBy('uuid')
            ->chunk(100, function ($users): void {
                foreach ($users as $user) {
                    $codes = collect((array) $user->two_factor_recovery_codes)
                        ->map(fn (mixed $code): string => str_starts_with((string) $code, 'sha256:')
                            ? (string) $code
                            : TwoFactor::hashRecoveryCodes([(string) $code])[0])
                        ->values()
                        ->all();

                    $user->forceFill(['two_factor_recovery_codes' => $codes])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Recovery-code hashing is intentionally irreversible.
    }
};
