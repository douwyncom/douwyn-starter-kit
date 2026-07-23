<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original starter-kit schema used a numeric ID and `user_uuid`,
        // while Laravel's DatabaseSessionHandler requires a string ID and
        // writes the authenticated identifier to `user_id`. Session data is
        // intentionally ephemeral, so rebuilding this malformed table is the
        // safest cross-database repair and logs existing browser sessions out.
        Schema::dropIfExists('sessions');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_uuid')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }
};
