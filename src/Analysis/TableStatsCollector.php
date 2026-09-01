<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How much data each table actually holds, read from the live database.
 *
 * This is the one analyzer that does not read source code, and it is deliberate: how big a table
 * is cannot be inferred from a model class, and it is the first thing anyone wants to know before
 * touching a query. A model named `IntegrationLog` tells you nothing; 28.8 MB across 1.4 million
 * rows tells you what you are dealing with.
 *
 * ## Two layers, because portability and detail are not the same thing
 *
 * The first layer is `Schema::getTables()`, which Laravel implements for every driver it supports
 * and which returns a total size — `pg_total_relation_size` on Postgres, `data_length +
 * index_length` on MySQL and MariaDB, `dbstat` on SQLite, its own sum on SQL Server. Nothing here
 * writes that SQL; the framework maintains it, across versions, for all five.
 *
 * The second layer is the part the framework does not expose: the row count and the split between
 * heap and indexes. That needs a query per driver, so it is written per driver — and a driver
 * without one degrades to a total size and no more, which is why every figure on
 * {@see TableStats} is nullable.
 *
 * ## Nothing here may break a scan
 *
 * Reading a live database is a new kind of dependency for a static analyzer, and the ways it can
 * fail are all ordinary: no connection configured, credentials that cannot read `pg_class`, a
 * schema that holds none of the application's tables, a driver nobody here anticipated. Every one
 * of those ends as an empty result and a scan that finishes. A missing number is a missing number;
 * it is never a reason for `brain:scan` to stop.
 */
class TableStatsCollector
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * The collector for a named connection, or the default one when the name is null.
     *
     * Resolution is the only thing here that needs a container, so it lives in a factory rather
     * than in the constructor — which leaves the collector itself something you can hand a
     * connection to and test against a real database.
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
     * Statistics for every table on the connection, keyed by table name.
     *
     * @return array<string, TableStats>
     */
    public function collect(): array
    {
        try {
            $totals = $this->totalSizes();
            $extras = $this->extras($this->connection);
        } catch (Throwable) {
            return [];
        }

        $tables = array_unique([...array_keys($totals), ...array_keys($extras)]);
        $stats = [];

        foreach ($tables as $table) {
            $extra = $extras[$table] ?? [];

            $stats[$table] = new TableStats(
                table: $table,
                rows: $extra['rows'] ?? null,
                tableBytes: $extra['tableBytes'] ?? null,
                indexBytes: $extra['indexBytes'] ?? null,
                // The framework's total is preferred over a driver query's, because it is the one
                // figure computed the same way on every driver — the numbers stay comparable
                // between two applications on two engines. `??` falls through when the framework
                // listed the table without a size, which is the case worth falling through for.
                totalBytes: $totals[$table] ?? $extra['totalBytes'] ?? null,
                rowsEstimated: $extra['rowsEstimated'] ?? true,
            );
        }

        return $stats;
    }

    /**
     * Every table on the connection, with its total size where the driver reports one.
     *
     * The size is nullable and the table is listed regardless, because those are two different
     * facts: SQLite compiled without `dbstat` answers `size => null` for a table that plainly
     * exists. Keying the list on having a size would drop the table from the graph entirely and
     * make a missing measurement look like a missing table.
     *
     * @return array<string, int|null>
     */
    private function totalSizes(): array
    {
        $sizes = [];

        foreach ($this->connection->getSchemaBuilder()->getTables() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $size = $table['size'] ?? null;
            $sizes[$name] = is_numeric($size) ? (int) $size : null;
        }

        return $sizes;
    }

    /**
     * Row counts and the heap/index split, where the driver can answer.
     *
     * @return array<string, array{rows?: int, tableBytes?: int, indexBytes?: int, totalBytes?: int, rowsEstimated?: bool}>
     */
    private function extras(Connection $connection): array
    {
        try {
            $driver = $connection->getDriverName();

            return match ($driver) {
                'pgsql' => $this->postgres($connection),
                'mysql', 'mariadb' => $this->mysql($connection),
                'sqlsrv' => $this->sqlServer($connection),
                // SQLite answers the total through `dbstat` and has nothing further to offer:
                // there is no row estimate short of counting, and no index/heap split.
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * `reltuples` is the planner's own estimate, maintained by ANALYZE and free to read. It is -1
     * on a table that has never been analysed, which is a real state and not an error — it is
     * reported as unknown rather than as zero rows.
     *
     * @return array<string, array<string, int|bool>>
     */
    private function postgres(Connection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
            select c.relname                        as table_name,
                   c.reltuples                      as est_rows,
                   pg_table_size(c.oid)             as table_bytes,
                   pg_indexes_size(c.oid)           as index_bytes,
                   pg_total_relation_size(c.oid)    as total_bytes
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where c.relkind in ('r', 'p')
              and n.nspname = current_schema()
        SQL);

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $estimate = (float) ($row['est_rows'] ?? -1);

            $out[(string) $row['table_name']] = [
                'rows' => $estimate < 0 ? null : (int) $estimate,
                'tableBytes' => (int) $row['table_bytes'],
                'indexBytes' => (int) $row['index_bytes'],
                'totalBytes' => (int) $row['total_bytes'],
                'rowsEstimated' => true,
            ];
        }

        return $out;
    }

    /**
     * `TABLE_ROWS` is exact on MyISAM and an estimate on InnoDB, which is what production tables
     * are. It is reported as an estimate throughout rather than claiming a precision that depends
     * on a storage engine the reader cannot see.
     *
     * @return array<string, array<string, int|bool>>
     */
    private function mysql(Connection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
            select table_name    as table_name,
                   table_rows    as est_rows,
                   data_length   as table_bytes,
                   index_length  as index_bytes
            from information_schema.tables
            where table_schema = database()
              and table_type = 'BASE TABLE'
        SQL);

        $out = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row);
            $table = (int) ($row['table_bytes'] ?? 0);
            $index = (int) ($row['index_bytes'] ?? 0);

            $out[(string) $row['table_name']] = [
                'rows' => isset($row['est_rows']) ? (int) $row['est_rows'] : null,
                'tableBytes' => $table,
                'indexBytes' => $index,
                'totalBytes' => $table + $index,
                'rowsEstimated' => true,
            ];
        }

        return $out;
    }

    /**
     * SQL Server keeps a maintained row count per partition, so this one figure is exact rather
     * than estimated. Index pages are everything the allocation stats hold minus the heap or
     * clustered index, which are the partitions with `index_id` 0 or 1.
     *
     * @return array<string, array<string, int|bool>>
     */
    private function sqlServer(Connection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
            select t.name as table_name,
                   sum(case when p.index_id in (0, 1) then p.row_count else 0 end) as est_rows,
                   sum(case when p.index_id in (0, 1) then p.used_page_count else 0 end) * 8192 as table_bytes,
                   sum(case when p.index_id not in (0, 1) then p.used_page_count else 0 end) * 8192 as index_bytes
            from sys.tables t
            join sys.dm_db_partition_stats p on p.object_id = t.object_id
            group by t.name
        SQL);

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $table = (int) ($row['table_bytes'] ?? 0);
            $index = (int) ($row['index_bytes'] ?? 0);

            $out[(string) $row['table_name']] = [
                'rows' => (int) ($row['est_rows'] ?? 0),
                'tableBytes' => $table,
                'indexBytes' => $index,
                'totalBytes' => $table + $index,
                'rowsEstimated' => false,
            ];
        }

        return $out;
    }
}
