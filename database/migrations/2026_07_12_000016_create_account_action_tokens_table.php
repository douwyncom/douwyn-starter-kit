<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_action_tokens', function (Blueprint $table): void {
            $table->uuid()->primary();
            $table->foreignUuid('user_uuid')->constrained('users', 'uuid')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('token_hash', 64)->unique();
            $table->string('auth_signature', 64);
            $table->string('source_email_hash', 64);
            $table->text('target_email')->nullable();
            $table->string('target_email_hash', 64)->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_uuid', 'type', 'consumed_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_action_tokens');
    }
};
