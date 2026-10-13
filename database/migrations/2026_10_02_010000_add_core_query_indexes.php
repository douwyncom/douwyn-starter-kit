<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // PostgreSQL builds these indexes without blocking application writes.
    public $withinTransaction = false;

    public function getConnection(): ?string
    {
        return parent::getConnection();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $postgres = $schema->getConnection()->getDriverName() === 'pgsql';

        if ($postgres && $this->searchIndexes() !== []) {
            $schema->getConnection()->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }

        foreach ($this->indexes() as [$table, $name, $columns, $expression]) {
            if ($columns === [] && ! $postgres) {
                continue;
            }

            $this->createIndex($table, $name, $columns, $expression);
        }

        if ($postgres) {
            foreach ($this->searchIndexes() as [$table, $name, $columns]) {
                // Match both API LIKE/ILIKE and Filament lower(column::text) LIKE.
                $grammar = $schema->getConnection()->getQueryGrammar();
                $expressions = [];
                foreach ($columns as $column) {
                    $wrapped = $grammar->wrap($column);
                    $expressions[] = $wrapped.' gin_trgm_ops';
                    $expressions[] = 'lower('.$wrapped.'::text) gin_trgm_ops';
                }
                $this->createIndex($table, $name, [], 'USING gin ('.implode(', ', $expressions).')');
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = $schema->getConnection();
        $postgres = $db->getDriverName() === 'pgsql';
        foreach (array_reverse(array_merge($this->indexes(), $postgres ? $this->searchIndexes() : [])) as [$table, $name, $columns]) {
            if (! $postgres && $columns === []) {
                continue;
            }
            if ($postgres) {
                $db->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$db->getQueryGrammar()->wrap($db->getTablePrefix().$name));
            } elseif ($schema->hasIndex($table, $name)) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
        // pg_trgm may be used by other modules, so rollback keeps the extension.
    }

    private function createIndex(string $table, string $name, array $columns, ?string $expression): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = $schema->getConnection();
        if ($db->getDriverName() !== 'pgsql') {
            if (! $schema->hasIndex($table, $name)) {
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }

            return;
        }

        $grammar = $db->getQueryGrammar();
        $index = $grammar->wrap($db->getTablePrefix().$name);
        $existing = $db->pretending() ? null : $db->selectOne('SELECT indisvalid, indisready FROM pg_index WHERE indexrelid = to_regclass(?)', [$index]);
        if ($existing !== null) {
            if ($existing->indisvalid && $existing->indisready) {
                return;
            }
            // A failed concurrent build leaves an invalid index; make retries repair it.
            $db->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
        $definition = $expression ?? '('.implode(', ', array_map($grammar->wrap(...), $columns)).')';
        $db->statement('CREATE INDEX CONCURRENTLY '.$index.' ON '.$grammar->wrapTable($table).' '.$definition);
    }

    /** @return list<array{string, string, list<string>, ?string}> */
    private function indexes(): array
    {
        return [
            ['personal_access_tokens', 'pat_device_session_lookup', ['api_device_session_id'], null],
            [config('permission.table_names.role_has_permissions'), 'role_permissions_reverse_lookup', [config('permission.column_names.role_pivot_key') ?: 'role_id', config('permission.column_names.permission_pivot_key') ?: 'permission_id'], null],
            ['users', 'users_created_cursor', ['created_at', 'uuid'], null],
            ['login_sessions', 'login_sessions_owner_active_cursor', ['user_uuid', 'revoked_at', 'last_active_at', 'id'], null],
            ['api_device_sessions', 'api_devices_owner_seen_cursor', ['user_uuid', 'last_seen_at', 'id'], null],
            ['activity_log', 'activity_subject_security_cursor', ['subject_type', 'subject_id', 'log_name', 'id'], null],
            ['users', 'users_email_lower_lookup', [], '(lower(email::text))'],
        ];
    }

    /** @return list<array{string, string, list<string>, ?string}> */
    private function searchIndexes(): array
    {
        return [
            ['users', 'users_search_trgm', ['email'], null],
        ];
    }
};
