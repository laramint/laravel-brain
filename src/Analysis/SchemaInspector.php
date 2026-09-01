<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The real shape of the tables the application's models read.
 *
 * Everything here goes through Laravel's own schema builder — `getColumns()`, `getIndexes()` and
 * `getForeignKeys()` — which returns the same normalised rows on PostgreSQL, MySQL, MariaDB,
 * SQLite and SQL Server. There is no SQL in this class and no branch on driver, because the
 * framework already did that work and maintains it across its own versions.
 *
 * Reading migrations instead was the obvious alternative and is the weaker one. A migration says
 * what was intended, in the order it was intended, and a project of any age has a schema that no
 * longer matches the sum of its migrations: indexes added during an incident, columns altered by
 * a later file, a `--pretend` that never ran. The catalogue is the only account of what is
 * actually there.
 *
 * Cost, measured against a 245-table application: 150 tables at three calls each in 0.97s. It is
 * scoped to the tables models actually read rather than everything the connection holds, so the
 * bill scales with the graph and not with the database.
 */
class SchemaInspector
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * The inspector for a named connection, or the default one when the name is null.
     *
     * Resolution is the only part that needs a container, so it lives here rather than in the
     * constructor — which leaves the inspector something you can hand a connection to and test
     * against a real database.
     */
    public static function forConnection(?string $connection = null): ?self
    {
        try {
            $resolved = DB::connection($connection);
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof Connection ? new self($resolved) : null;
    }

    /**
     * Read the named tables, skipping any the connection does not have.
     *
     * @param  list<string>  $tables
     * @return array<string, TableSchema>
     */
    public function inspect(array $tables): array
    {
        try {
            $existing = array_flip(array_map(
                static fn (array $table): string => (string) ($table['name'] ?? ''),
                $this->connection->getSchemaBuilder()->getTables(),
            ));
        } catch (Throwable) {
            return [];
        }

        $schemas = [];

        foreach (array_unique($tables) as $table) {
            if ($table === '' || ! isset($existing[$table])) {
                continue;
            }

            $schema = $this->read($table);

            if ($schema !== null) {
                $schemas[$table] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * One table, or null when it cannot be read.
     *
     * Guarded per table rather than around the whole set: a view that answers to `getTables()`
     * but not to `getForeignKeys()`, or one table whose permissions differ, must not cost the
     * other hundred and fifty their schema.
     */
    private function read(string $table): ?TableSchema
    {
        try {
            $builder = $this->connection->getSchemaBuilder();

            return new TableSchema(
                table: $table,
                columns: $this->columns($builder->getColumns($table)),
                indexes: $this->indexes($builder->getIndexes($table)),
                foreignKeys: $this->foreignKeys($builder->getForeignKeys($table)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool}>
     */
    private function columns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            $default = $row['default'] ?? null;

            $columns[] = [
                'name' => (string) ($row['name'] ?? ''),
                // `type` carries the engine's full spelling (`varchar(255)`), `type_name` the bare
                // one. The full spelling is the useful one on a schema screen: a column's width is
                // half of what anyone is looking for.
                'type' => (string) ($row['type'] ?? $row['type_name'] ?? ''),
                'nullable' => (bool) ($row['nullable'] ?? false),
                'default' => $default === null ? null : (string) $default,
                'autoIncrement' => (bool) ($row['auto_increment'] ?? false),
            ];
        }

        return $columns;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    private function indexes(array $rows): array
    {
        $indexes = [];

        foreach ($rows as $row) {
            $indexes[] = [
                'name' => (string) ($row['name'] ?? ''),
                'columns' => array_values(array_map('strval', (array) ($row['columns'] ?? []))),
                'unique' => (bool) ($row['unique'] ?? false),
                'primary' => (bool) ($row['primary'] ?? false),
            ];
        }

        return $indexes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{name: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    private function foreignKeys(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys[] = [
                'name' => (string) ($row['name'] ?? ''),
                'columns' => array_values(array_map('strval', (array) ($row['columns'] ?? []))),
                'foreignTable' => (string) ($row['foreign_table'] ?? ''),
                'foreignColumns' => array_values(array_map('strval', (array) ($row['foreign_columns'] ?? []))),
                'onDelete' => isset($row['on_delete']) ? (string) $row['on_delete'] : null,
                'onUpdate' => isset($row['on_update']) ? (string) $row['on_update'] : null,
            ];
        }

        return $keys;
    }
}
