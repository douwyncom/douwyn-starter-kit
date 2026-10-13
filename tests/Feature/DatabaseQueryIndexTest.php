<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function installedCoreQueryIndexMigrations(): array
{
    $paths = glob(database_path('migrations/2026_10_02_*_query_indexes.php'));
    $installed = [];
    foreach ($paths as $path) {
        $migration = require $path;
        $definitions = (new ReflectionMethod($migration, 'indexes'))->invoke($migration);
        $schema = Schema::connection($migration->getConnection());
        $tables = array_unique(array_column($definitions, 0));
        if (collect($tables)->every(fn (string $table): bool => $schema->hasTable($table))) {
            $installed[] = [$migration, $tables];
        }
    }

    return $installed;
}

function installedQueryIndexGroups(): array
{
    $groups = [];
    foreach (installedCoreQueryIndexMigrations() as [$migration]) {
        $groups[] = [
            'connection' => $migration->getConnection(),
            'indexes' => (new ReflectionMethod($migration, 'indexes'))->invoke($migration),
            'search_indexes' => (new ReflectionMethod($migration, 'searchIndexes'))->invoke($migration),
        ];
    }

    $manifest = base_path('modules/testing/query-indexes.php');
    if (! is_file($manifest) && glob(base_path('modules/starter-kit-*/database/migrations/*.php')) !== []) {
        throw new LogicException('Private module migrations require the query-index regression catalog.');
    }
    foreach (is_file($manifest) ? require $manifest : [] as $definition) {
        $definition['connection'] = config($definition['connection']) ?: null;
        $groups[] = $definition;
    }

    // Inspect every present table, even if another optional table is not installed.
    // Consolidated create migrations must never be called again on existing tables.
    foreach ($groups as &$group) {
        $schema = Schema::connection($group['connection']);
        foreach (['indexes', 'search_indexes'] as $kind) {
            $group[$kind] = array_values(array_filter(
                $group[$kind],
                fn (array $index): bool => $schema->hasTable($index[0]),
            ));
        }
        $group['tables'] = array_unique(array_column([...$group['indexes'], ...$group['search_indexes']], 0));
    }
    unset($group);

    return array_values(array_filter($groups, fn (array $group): bool => $group['tables'] !== []));
}

it('covers foreign keys in the optimized tables with a leading index', function (): void {
    $missing = [];
    $groups = installedQueryIndexGroups();
    expect($groups)->not->toBeEmpty();
    foreach ($groups as $group) {
        $schema = Schema::connection($group['connection']);
        foreach ($group['tables'] as $table) {
            $indexes = $schema->getIndexes($table);
            foreach ($schema->getForeignKeys($table) as $foreign) {
                $columns = $foreign['columns'];
                $covered = collect($indexes)->contains(function (array $index) use ($columns): bool {
                    $leading = array_slice($index['columns'], 0, count($columns));
                    sort($leading);
                    sort($columns);

                    return $leading === $columns;
                });
                if (! $covered) {
                    $missing[] = $table.'.'.implode(',', $columns);
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

it('preserves optimized index names and column order in the installed final schema', function (): void {
    $groups = installedQueryIndexGroups();
    expect($groups)->not->toBeEmpty();
    foreach ($groups as $group) {
        $schema = Schema::connection($group['connection']);
        $db = $schema->getConnection();
        $postgres = $db->getDriverName() === 'pgsql';

        foreach ($group['indexes'] as [$table, $name, $columns]) {
            if ($columns === [] && ! $postgres) {
                continue;
            }

            $databaseName = ($postgres ? $db->getTablePrefix() : '').$name;
            $index = collect($schema->getIndexes($table))->firstWhere('name', $databaseName);
            expect($index)->not->toBeNull("Missing optimized index $name on $table");
            expect($index['unique'])->toBeFalse()->and($index['primary'])->toBeFalse();
            if ($columns !== []) {
                expect($index['columns'])->toBe($columns, "Column order changed for $name");
            }
        }

        if (! $postgres) {
            continue;
        }
        foreach ($group['search_indexes'] as [$table, $name, $columns]) {
            $databaseName = $db->getTablePrefix().$name;
            $index = collect($schema->getIndexes($table))->firstWhere('name', $databaseName);
            expect($index)->not->toBeNull("Missing search index $name on $table");
            expect($index['type'])->toBe('gin');
            $positions = $db->select(
                'SELECT a.attname FROM pg_index i CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, position) LEFT JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum WHERE i.indexrelid = to_regclass(?) ORDER BY k.position',
                [$db->getQueryGrammar()->wrap($db->getTablePrefix().$name)],
            );
            expect(array_column($positions, 'attname'))->toBe(
                array_merge(...array_map(fn (string $column): array => [$column, null], $columns)),
                "Raw/lowercase column order changed for $name",
            );
        }
    }
});

it('can retry and roll back the remaining core query index migrations without removing existing indexes', function (): void {
    $migrations = installedCoreQueryIndexMigrations();
    expect($migrations)->not->toBeEmpty();
    foreach ($migrations as [$migration]) {
        $migration->up();
        $migration->up();
        $migration->down();
        $migration->down();
        $migration->up();
    }

    expect(Schema::hasIndex('users', ['email'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('personal_access_tokens', 'pat_device_session_lookup'))->toBeTrue();
    if (Schema::hasTable('library_asset_tag')) {
        expect(Schema::hasIndex('library_asset_tag', 'library_tag_assets_reverse_lookup'))->toBeTrue();
    }
});
