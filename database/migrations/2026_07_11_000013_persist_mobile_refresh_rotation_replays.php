<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_device_sessions', function (Blueprint $table): void {
            $table->json('abilities')->nullable()->after('device_id_hash');
        });

        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->string('rotation_request_hash', 64)->nullable()->index()->after('replaced_by_id');
            $table->string('rotation_device_hash', 64)->nullable()->after('rotation_request_hash');
            $table->text('rotation_response')->nullable()->after('rotation_device_hash');
            $table->timestamp('rotation_expires_at')->nullable()->index()->after('rotation_response');
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->dropIndex(['rotation_request_hash']);
            $table->dropIndex(['rotation_expires_at']);
            $table->dropColumn([
                'rotation_request_hash',
                'rotation_device_hash',
                'rotation_response',
                'rotation_expires_at',
            ]);
        });

        Schema::table('api_device_sessions', function (Blueprint $table): void {
            $table->dropColumn('abilities');
        });
    }
};
