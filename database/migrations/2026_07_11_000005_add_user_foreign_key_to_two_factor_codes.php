<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('two_factor_codes')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('users')
                ->whereColumn('users.uuid', 'two_factor_codes.user_uuid'))
            ->delete();

        Schema::table('two_factor_codes', function (Blueprint $table): void {
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('two_factor_codes', function (Blueprint $table): void {
            $table->dropForeign(['user_uuid']);
        });
    }
};
