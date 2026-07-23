<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUuid('api_device_session_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained('api_device_sessions')
                ->cascadeOnDelete();
            $table->string('client_type', 20)->default('legacy')->after('name')->index();
            $table->string('issued_ip_address', 45)->nullable()->after('client_type');
            $table->text('issued_user_agent')->nullable()->after('issued_ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropForeign(['api_device_session_id']);
            $table->dropColumn([
                'api_device_session_id',
                'client_type',
                'issued_ip_address',
                'issued_user_agent',
            ]);
        });
    }
};
