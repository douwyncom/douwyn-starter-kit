<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->dropIndex('activity_log_security_event_created_index');
                $table->index(
                    ['log_name', 'created_at', 'event'],
                    'activity_log_security_created_event_index',
                );
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table): void {
                $table->dropIndex('activity_log_security_created_event_index');
                $table->index(
                    ['log_name', 'event', 'created_at'],
                    'activity_log_security_event_created_index',
                );
            });
    }
};
