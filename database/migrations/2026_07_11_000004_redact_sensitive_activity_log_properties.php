<?php

use App\Support\ActivityLogSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        DB::connection($connection)
            ->table($table)
            ->select(['id', 'properties'])
            ->orderBy('id')
            ->chunkById(200, function ($activities) use ($connection, $table): void {
                foreach ($activities as $activity) {
                    if ($activity->properties === null) {
                        continue;
                    }

                    $sanitized = ActivityLogSanitizer::sanitize($activity->properties);

                    DB::connection($connection)
                        ->table($table)
                        ->where('id', $activity->id)
                        ->update(['properties' => json_encode($sanitized, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        // Redaction is intentionally irreversible.
    }
};
