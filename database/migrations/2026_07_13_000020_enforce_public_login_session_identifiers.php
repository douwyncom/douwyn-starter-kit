<?php

use App\Services\Auth\LoginSessionIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $identifiers = app(LoginSessionIdentifier::class);

        DB::table('login_sessions')
            ->whereNull('public_id_hash')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($sessions) use ($identifiers): void {
                foreach ($sessions as $session) {
                    DB::table('login_sessions')
                        ->where('id', $session->id)
                        ->whereNull('public_id_hash')
                        ->update([
                            'public_id_hash' => $identifiers->digest((string) $session->id),
                        ]);
                }
            }, 'id');

        Schema::table('login_sessions', function (Blueprint $table): void {
            $table->string('public_id_hash', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('login_sessions', function (Blueprint $table): void {
            $table->string('public_id_hash', 64)->nullable()->change();
        });
    }
};
