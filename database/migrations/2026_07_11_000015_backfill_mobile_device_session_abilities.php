<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_device_sessions')
            ->whereNull('abilities')
            ->select(['id', 'revoked_at', 'revoke_reason'])
            ->orderBy('id')
            ->chunkById(250, function ($sessions): void {
                foreach ($sessions as $session) {
                    $storedAbilities = DB::table('personal_access_tokens')
                        ->where('api_device_session_id', $session->id)
                        ->latest('created_at')
                        ->value('abilities');
                    $abilities = $this->decodeAbilities($storedAbilities);

                    if ($abilities !== null) {
                        DB::table('api_device_sessions')
                            ->where('id', $session->id)
                            ->update([
                                'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ]);

                        continue;
                    }

                    $now = now();
                    DB::table('api_device_sessions')
                        ->where('id', $session->id)
                        ->update([
                            'abilities' => json_encode([], JSON_THROW_ON_ERROR),
                            'revoked_at' => $session->revoked_at ?? $now,
                            'revoke_reason' => $session->revoke_reason ?? 'security_changed',
                            'updated_at' => $now,
                        ]);
                    DB::table('refresh_tokens')
                        ->where('api_device_session_id', $session->id)
                        ->whereNull('revoked_at')
                        ->update([
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Ability provenance cannot be reconstructed safely after backfill.
        // Migration 000013 removes this column when the full feature rolls back.
    }

    /** @return list<string>|null */
    private function decodeAbilities(mixed $storedAbilities): ?array
    {
        if (! is_string($storedAbilities)) {
            return null;
        }

        try {
            $abilities = json_decode($storedAbilities, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($abilities)) {
            return null;
        }

        foreach ($abilities as $ability) {
            if (! is_string($ability) || trim($ability) === '') {
                return null;
            }
        }

        return array_values(array_unique($abilities));
    }
};
