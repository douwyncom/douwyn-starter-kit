<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('two_factor_codes', function (Blueprint $table) {
            $table->uuid()->primary();

            $table->uuid('user_uuid')->index();
            $table->string('channel', 20)->default('email');
            $table->string('sent_to', 190)->nullable();
            $table->string('purpose', 190)->default('login');

            $table->string('code_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('two_factor_codes');
    }
};
