<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_challenges', function (Blueprint $table): void {
            $table->string('device_id_hash', 64)->nullable()->after('device_name');
            $table->string('platform', 20)->nullable()->after('device_id_hash');
            $table->string('app_version', 50)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('auth_challenges', function (Blueprint $table): void {
            $table->dropColumn(['device_id_hash', 'platform', 'app_version']);
        });
    }
};
