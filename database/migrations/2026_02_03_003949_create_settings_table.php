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
        Schema::create('settings', function (Blueprint $table) {
            $table->uuid()->primary();

            $table->string('group', 100)->default('general')->index(); // general, mail, payment...
            $table->string('key', 150)->index();                        // site_name, mail.from_address...

            $table->string('type', 30)->default('string'); // json, array,...
            $table->longText('value')->nullable();

            $table->boolean('is_encrypted')->default(false);
            $table->boolean('autoload')->default(true);                 // load to cache after boot?

            $table->json('meta')->nullable();                           // label, help, options...

            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
