<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_device_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_uuid')->constrained('users', 'uuid')->cascadeOnDelete();
            $table->string('device_id_hash', 64);
            $table->string('device_name', 100);
            $table->string('platform', 20);
            $table->string('app_version', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('refresh_expires_at')->index();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_uuid', 'revoked_at']);
            $table->index(['user_uuid', 'device_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_device_sessions');
    }
};
