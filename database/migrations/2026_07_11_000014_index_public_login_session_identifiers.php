<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_sessions', function (Blueprint $table): void {
            $table->string('public_id_hash', 64)->nullable();
        });

        DB::table('login_sessions')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($sessions): void {
                foreach ($sessions as $session) {
                    DB::table('login_sessions')
                        ->where('id', $session->id)
                        ->update([
                            'public_id_hash' => hash_hmac(
                                'sha256',
                                "browser-login-session\0{$session->id}",
                                (string) config('app.key'),
                            ),
                        ]);
                }
            }, 'id');

        Schema::table('login_sessions', function (Blueprint $table): void {
            $table->unique('public_id_hash');
        });
    }

    public function down(): void
    {
        Schema::table('login_sessions', function (Blueprint $table): void {
            $table->dropUnique(['public_id_hash']);
            $table->dropColumn('public_id_hash');
        });
    }
};
