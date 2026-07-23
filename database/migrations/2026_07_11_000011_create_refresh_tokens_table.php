<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('api_device_session_id')
                ->constrained('api_device_sessions')
                ->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->uuid('replaced_by_id')->nullable()->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['api_device_session_id', 'revoked_at']);
        });

        // PostgreSQL must see the table's primary key before it can validate a
        // self-referencing foreign key created by a separate ALTER TABLE.
        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->foreign('replaced_by_id')
                ->references('id')
                ->on('refresh_tokens')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
