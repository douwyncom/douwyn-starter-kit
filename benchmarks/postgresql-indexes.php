<?php

/**
 * Reproducible PostgreSQL index benchmark. Run from the project root:
 * php benchmarks/postgresql-indexes.php [rows-per-large-table] [result.json]
 * Creates a NEW starter_kit_index_benchmark_* database and removes only that database.
 * Never imports real user data or runs migrations against the configured application database.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'pgsql') {
    throw new RuntimeException('This benchmark requires a PostgreSQL source connection.');
}
$rows = (int) ($argv[1] ?? 200000);
if ($rows < 10000 || $rows > 1000000) {
    throw new RuntimeException('Rows must be between 10,000 and 1,000,000.');
}
$output = $argv[2] ?? sys_get_temp_dir().'/postgresql-index-benchmark.json';
$root = dirname(__DIR__);
$database = 'starter_kit_index_benchmark_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
$admin = DB::connection('pgsql');
$quotedDatabase = '"'.$database.'"';
$created = false;
$fixture = null;

function benchmarkMeasure($db, string $sql): array
{
    // One warm-up and seven measured EXPLAIN executions; medians reduce run-to-run noise.
    $plans = [];
    for ($i = 0; $i < 8; $i++) {
        $plan = json_decode($db->selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql)->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        if ($i > 0) {
            $plans[] = $plan;
        }
    }
    $times = array_column($plans, 'Execution Time');
    sort($times);
    $indexes = [];
    $walk = function (array $node) use (&$walk, &$indexes): void {
        if (isset($node['Index Name'])) {
            $indexes[] = $node['Index Name'];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plans[3]['Plan']);

    return [
        'median_ms' => $times[3], 'min_ms' => $times[0], 'max_ms' => $times[6],
        'shared_hit_blocks' => $plans[3]['Plan']['Shared Hit Blocks'] ?? 0,
        'shared_read_blocks' => $plans[3]['Plan']['Shared Read Blocks'] ?? 0,
        'indexes' => array_values(array_unique($indexes)), 'plan' => $plans[3],
    ];
}

function benchmarkWrites($db, int $rows, int $start): array
{
    $times = [];
    for ($i = 0; $i < 5; $i++) {
        $db->beginTransaction();
        try {
            $begin = hrtime(true);
            $db->statement("INSERT INTO operations_health_results (uuid, probe_key, status, summary, observed_at) SELECT ('01980001-0000-7000-8000-' || lpad(n::text,12,'0'))::uuid, 'write_probe', 'ok', 'synthetic', TIMESTAMPTZ '2026-01-01' + n * INTERVAL '1 second' FROM generate_series($start, ".($start + $rows - 1).') n');
            $times[] = (hrtime(true) - $begin) / 1000000;
        } finally {
            $db->rollBack();
        }
    }
    sort($times);

    return ['rows_per_insert' => $rows, 'median_ms' => $times[2], 'samples_ms' => $times];
}

function benchmarkCaptureIndexes($db, string $database, array $definitions): array
{
    if (! str_starts_with($database, 'starter_kit_index_benchmark_')
        || $db->getDatabaseName() !== $database
        || $db->selectOne('SELECT current_database() AS name')->name !== $database) {
        throw new RuntimeException('Index changes are allowed only in the isolated benchmark database.');
    }

    $grammar = $db->getQueryGrammar();
    $captured = [];
    foreach ($definitions as [$table, $name]) {
        if (isset($captured[$name])) {
            throw new RuntimeException("Duplicate benchmark index $name.");
        }
        $index = $db->selectOne(
            'SELECT n.nspname AS schema_name, c.relname AS index_name, pg_get_indexdef(i.indexrelid) AS definition, i.indisunique, i.indisprimary, i.indisvalid, i.indisready, EXISTS (SELECT 1 FROM pg_constraint co WHERE co.conindid = i.indexrelid) AS constraint_owned FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE i.indexrelid = to_regclass(?) AND i.indrelid = to_regclass(?)',
            [$grammar->wrap($db->getTablePrefix().$name), $grammar->wrapTable($table)],
        );
        if ($index === null || $index->indisunique || $index->indisprimary || $index->constraint_owned
            || ! $index->indisvalid || ! $index->indisready) {
            throw new RuntimeException("Cannot benchmark missing, invalid, or constraint-owned index $name on $table.");
        }

        $captured[$name] = [
            'table' => $table,
            'name' => $name,
            'qualified_name' => $grammar->wrap($index->schema_name).'.'.$grammar->wrap($index->index_name),
            'definition' => $index->definition,
        ];
    }

    return array_values($captured);
}

try {
    $admin->statement('CREATE DATABASE '.$quotedDatabase);
    $created = true;
    echo "Created isolated benchmark database.\n";
    $configuration = config('database.connections.pgsql');
    $configuration['url'] = null;
    $configuration['database'] = $database;
    config(['database.connections.index_benchmark' => $configuration, 'database.default' => 'index_benchmark', 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
    $moduleNames = ['ai', 'alerts', 'backup', 'blog', 'blog-ai', 'blog-library', 'domains', 'library', 'library-ai', 'licensing', 'operations', 'telegram'];
    $modulePaths = [];
    foreach ($moduleNames as $moduleName) {
        $module = 'starter-kit-'.$moduleName;
        config([$module.'.connection' => 'index_benchmark']);
        array_push($modulePaths, ...glob($root.'/modules/'.$module.'/database/migrations/*.php'));
    }
    $fixture = DB::connection('index_benchmark');
    $fixture->statement("SET statement_timeout = '120s'");
    $fixture->statement("SET lock_timeout = '5s'");
    $paths = array_merge(glob($root.'/database/migrations/*.php'), $modulePaths);
    $corePaths = glob($root.'/database/migrations/2026_10_02_*_query_indexes.php');
    $coreMigrations = [];
    $definitions = [];
    foreach ($corePaths as $path) {
        $migration = require $path;
        $coreMigrations[] = $migration;
        array_push($definitions, ...(new ReflectionMethod($migration, 'indexes'))->invoke($migration));
        array_push($definitions, ...(new ReflectionMethod($migration, 'searchIndexes'))->invoke($migration));
    }
    $manifestPath = $root.'/modules/testing/query-indexes.php';
    if (! is_file($manifestPath)) {
        throw new RuntimeException('Install the private module query-index benchmark catalog first.');
    }
    foreach (require $manifestPath as $module => $definition) {
        if (in_array(substr($module, strlen('starter-kit-')), $moduleNames, true)) {
            array_push($definitions, ...$definition['indexes'], ...$definition['search_indexes']);
        }
    }

    $status = $kernel->call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
    if ($status !== 0) {
        throw new RuntimeException($kernel->output());
    }
    // Module indexes now belong to create migrations. Capture their canonical final
    // DDL, then temporarily remove only this explicit allowlist in the new fixture.
    $benchmarkIndexes = benchmarkCaptureIndexes($fixture, $database, $definitions);
    if ($benchmarkIndexes === []) {
        throw new RuntimeException('No optimized indexes were selected for the benchmark.');
    }
    foreach ($benchmarkIndexes as $index) {
        $fixture->statement('DROP INDEX '.$index['qualified_name']);
    }
    echo "Final schema applied; benchmark indexes removed; seeding {$rows} rows in each large table.\n";
    $uuid = fn (string $n): string => "('01980000-0000-7000-8000-' || lpad(($n)::text,12,'0'))::uuid";
    $u = $uuid('n');
    $one = $uuid('1');
    $owner = $uuid('(n % 2000) + 1');
    $fixture->statement("INSERT INTO users (uuid,email,password,created_at,updated_at) SELECT $u, 'person-'||n||'@benchmark.invalid', 'synthetic-hash', TIMESTAMP '2026-01-01'+n*INTERVAL '1 second', TIMESTAMP '2026-01-01'+n*INTERVAL '1 second' FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO api_device_sessions (id,user_uuid,device_id_hash,device_name,platform,refresh_expires_at,absolute_expires_at) SELECT $u,$one,md5(n::text),'synthetic','ios',TIMESTAMP '2030-01-01',TIMESTAMP '2030-01-01' FROM generate_series(1,2000) n");
    $fixture->statement("INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,api_device_session_id,name,token,created_at,updated_at) SELECT 'App\\Models\\User',$one,$owner,'synthetic',md5(n::text),TIMESTAMP '2026-01-01',TIMESTAMP '2026-01-01' FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO operations_health_results (uuid,probe_key,status,summary,observed_at) SELECT $u, 'probe-'||(n % 20), CASE WHEN n % 100=0 THEN 'warning' ELSE 'ok' END,'synthetic',TIMESTAMPTZ '2026-01-01'+((n/20)::integer)*INTERVAL '1 second' FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO blog_posts (uuid,author_uuid,status,published_at,created_at,updated_at) SELECT $u,$owner,CASE WHEN n % 5=0 THEN 'draft' ELSE 'published' END,TIMESTAMPTZ '2026-01-01'+n*INTERVAL '1 second',TIMESTAMPTZ '2026-01-01'+n*INTERVAL '1 second',TIMESTAMPTZ '2026-01-01'+((n/10)::integer)*INTERVAL '1 second' FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO blog_post_translations (uuid,post_uuid,locale,title,slug,excerpt,published) SELECT $u,$u,'vi',CASE WHEN n % 5000=0 THEN 'PostgreSQL needle-search '||n ELSE 'Article '||md5(n::text) END,'article-'||n,'Synthetic content only',true FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO library_storage_profiles (uuid,name,driver) VALUES ($one,'synthetic','local')");
    $fixture->statement("INSERT INTO library_assets (uuid,name,original_name,storage_profile_uuid,disk,path,mime_type,extension,size_bytes,checksum,status,created_at,updated_at) SELECT $u,CASE WHEN n%5000=0 THEN 'needle-search-'||n ELSE 'file-'||md5(n::text) END,'file-'||n,$one,'local','synthetic/'||n,'image/png','png',1024,md5(n::text),'ready',TIMESTAMP '2026-01-01'+n*INTERVAL '1 second',TIMESTAMP '2026-01-01'+n*INTERVAL '1 second' FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO library_tags (uuid,name) SELECT $u,'tag-'||n FROM generate_series(1,2000) n");
    $fixture->statement("INSERT INTO library_asset_tag (asset_uuid,tag_uuid) SELECT $u,$owner FROM generate_series(1,$rows) n");
    $fixture->statement("INSERT INTO ai_connections (uuid,name,driver,api_key) VALUES ($one,'synthetic','synthetic','synthetic')");
    $fixture->statement("INSERT INTO ai_profiles (uuid,name,connection_uuid,model) VALUES ($one,'synthetic',$one,'synthetic')");
    $fixture->statement("INSERT INTO ai_runs (uuid,actor_uuid,profile_uuid,task,locale,status,idempotency_key,created_at,updated_at) SELECT $u,$owner,$one,'benchmark','vi','succeeded','run-'||n,TIMESTAMP '2026-01-01'+n*INTERVAL '1 second',TIMESTAMP '2026-01-01'+n*INTERVAL '1 second' FROM generate_series(1,$rows) n");
    $fixture->statement('ANALYZE');
    $queries = [
        'email_lower_lookup' => "SELECT uuid FROM users WHERE lower(email)='person-".($rows - 1)."@benchmark.invalid' AND is_inactive=false LIMIT 1",
        'mobile_session_token_lookup' => "SELECT id FROM personal_access_tokens WHERE api_device_session_id=$one",
        'health_status_history' => "SELECT uuid,status,observed_at FROM operations_health_results WHERE status='warning' ORDER BY observed_at DESC,uuid DESC LIMIT 25",
        'blog_managed_cursor' => 'SELECT uuid,updated_at FROM blog_posts ORDER BY updated_at DESC,uuid DESC LIMIT 25',
        'blog_search_api' => "SELECT uuid FROM blog_post_translations WHERE title LIKE '%needle-search%' OR excerpt LIKE '%needle-search%'",
        'blog_search_filament' => "SELECT uuid FROM blog_post_translations WHERE lower(title::text) LIKE '%needle-search%' OR lower(slug::text) LIKE '%needle-search%'",
        'library_search_api' => "SELECT uuid FROM library_assets WHERE deleted_at IS NULL AND name LIKE '%needle-search%' ORDER BY created_at DESC,uuid ASC LIMIT 25",
        'library_reverse_tag' => "SELECT asset_uuid FROM library_asset_tag WHERE tag_uuid=$one ORDER BY asset_uuid LIMIT 25",
        'ai_owner_history' => "SELECT uuid,created_at FROM ai_runs WHERE actor_uuid=$one ORDER BY created_at DESC,uuid DESC LIMIT 25",
        'health_deep_offset' => 'SELECT uuid,observed_at FROM operations_health_results ORDER BY observed_at DESC,uuid DESC OFFSET '.intdiv($rows, 2).' LIMIT 25',
        'health_deep_cursor' => "SELECT uuid,observed_at FROM operations_health_results WHERE (observed_at,uuid) < (TIMESTAMPTZ '2026-01-01'+".intdiv($rows, 40)."*INTERVAL '1 second',".$uuid((string) intdiv($rows, 2)).') ORDER BY observed_at DESC,uuid DESC LIMIT 25',
    ];
    $result = ['postgres' => $fixture->selectOne('SHOW server_version')->server_version, 'rows_per_large_table' => $rows, 'seeded_rows' => $rows * 8 + 4003, 'fixture_only' => true, 'method' => 'EXPLAIN ANALYZE BUFFERS; one warm-up + seven samples; warm-cache median; same data before/after', 'queries' => []];
    foreach ($queries as $name => $sql) {
        $result['queries'][$name] = ['sql' => $sql, 'before' => benchmarkMeasure($fixture, $sql)];
        echo "Baseline: {$name}\n";
    }
    $result['writes']['before'] = benchmarkWrites($fixture, 3000, $rows + 1);
    $indexSize = fn () => (int) $fixture->selectOne("SELECT sum(pg_indexes_size(oid)) AS bytes FROM pg_class WHERE relkind='r' AND relnamespace='public'::regnamespace")->bytes;
    $result['index_bytes']['before'] = $indexSize();
    foreach ($benchmarkIndexes as $index) {
        $fixture->statement($index['definition']);
    }
    $fixture->statement('ANALYZE');
    $result['index_bytes']['after'] = $indexSize();
    foreach ($queries as $name => $sql) {
        $result['queries'][$name]['after'] = benchmarkMeasure($fixture, $sql);
        $before = $result['queries'][$name]['before']['median_ms'];
        $after = $result['queries'][$name]['after']['median_ms'];
        $result['queries'][$name]['speedup'] = round($before / max(0.001, $after), 2);
        echo "After: {$name}: {$before} ms -> {$after} ms\n";
    }
    $result['writes']['after'] = benchmarkWrites($fixture, 3000, $rows + 1);
    $result['benchmark_indexes'] = $benchmarkIndexes;
    $result['core_query_index_migrations'] = array_map(fn ($p) => substr($p, strlen($root) + 1), $corePaths);
    $result['schema_strategy'] = 'final consolidated schema; allowlisted non-constraint indexes removed for before and restored from PostgreSQL DDL for after';
    $invalid = $fixture->select('SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE NOT i.indisvalid OR NOT i.indisready');
    if ($invalid !== []) {
        throw new RuntimeException('Invalid indexes remain after migration.');
    }
    // Only core retains additive migrations: create migrations are not repeatable
    // on existing tables and must not be used for retry/rollback smoke tests.
    $repairMigration = null;
    foreach ($coreMigrations as $migration) {
        $migration->up();
        $migration->down();
        $migration->down();
        $migration->up();
        if (in_array('users_email_lower_lookup', array_column((new ReflectionMethod($migration, 'indexes'))->invoke($migration), 1), true)) {
            $repairMigration = $migration;
        }
    }
    $repairedConcurrentIndex = false;
    if ($repairMigration !== null) {
        $fixture->statement('DROP INDEX CONCURRENTLY users_email_lower_lookup');
        try {
            // A duplicate constant forces an invalid build without changing user data.
            $fixture->statement('CREATE UNIQUE INDEX CONCURRENTLY users_email_lower_lookup ON users ((1))');
            throw new RuntimeException('Expected the synthetic unique-index build to fail.');
        } catch (UniqueConstraintViolationException) {
            $invalid = $fixture->selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'users_email_lower_lookup'::regclass");
            if ($invalid->indisvalid) {
                throw new RuntimeException('The synthetic failed build was unexpectedly valid.');
            }
        }
        $repairMigration->up();
        $repaired = $fixture->selectOne("SELECT indisvalid, indisready FROM pg_index WHERE indexrelid = 'users_email_lower_lookup'::regclass");
        if (! $repaired->indisvalid || ! $repaired->indisready) {
            throw new RuntimeException('Retry did not repair the failed concurrent core index.');
        }
        $repairedConcurrentIndex = true;
    }
    $result['migration_smoke'] = [
        'final_schema_installed' => true,
        'core_retry_rollback_reapply_count' => count($coreMigrations),
        'core_failed_concurrent_index_repaired' => $repairedConcurrentIndex,
        'all_benchmark_indexes_restored' => benchmarkCaptureIndexes($fixture, $database, $definitions) === $benchmarkIndexes,
    ];
    if (! $result['migration_smoke']['all_benchmark_indexes_restored']) {
        throw new RuntimeException('The benchmark did not restore the exact final index definitions.');
    }
    file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    echo "Saved {$output}\n";
} finally {
    if ($created) {
        DB::disconnect('index_benchmark');
        $admin->statement('DROP DATABASE '.$quotedDatabase.' WITH (FORCE)');
        echo "Removed isolated benchmark database.\n";
    }
}
