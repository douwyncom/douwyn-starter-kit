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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->uuid()->primary();

            // 1-1 with users
            $table->uuid('user_uuid')->unique()->index();
            $table->foreign('user_uuid')->references('uuid')->on('users')->cascadeOnDelete();

            // Basic profile
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->default('other');

            // Contact / address
            $table->string('phone', 30)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 30)->nullable();

            // Social / bio
            $table->string('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('website')->nullable();

            // Preferences
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 50)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
