<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * The shape of one table as the database itself reports it.
 *
 * Read from the live schema rather than from migrations, and the difference is not convenience.
 * Migrations describe what someone intended at the time they were written: they do not know about
 * an index added by hand during an incident, a column a later migration altered, or a staging
 * database that drifted from production. The catalogue knows. It is also the only source that
 * answers identically on every engine — Laravel normalises `getColumns`, `getIndexes` and
 * `getForeignKeys` across all five it supports, so nothing here parses SQL or a schema dump.
 */
final class TableSchema
{
    /**
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool}>  $columns
     * @param  list<array{name: string, columns: list<string>, unique: bool, primary: bool}>  $indexes
     * @param  list<array{name: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}>  $foreignKeys
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
    ) {}

    /**
     * Foreign keys with nothing to read them by.
     *
     * A foreign key constrains writes; it does not make reads fast, and on PostgreSQL and SQL
     * Server it creates no index at all. Every `belongsTo` traversal, every cascading delete and
     * every `whereHas` against such a column is then a full scan of the child table — the kind of
     * query that is instant on a developer's seed data and pathological on a real one.
     *
     * An index covers a key when the key's columns are a *leading prefix* of the index's. A
     * composite index on `(order_id, created_at)` covers a key on `order_id`; one on
     * `(created_at, order_id)` does not, because the engine cannot seek into it by the second
     * column alone. Order is the whole of it, which is why this compares sequences and not sets.
     *
     * @return list<array{name: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>, onDelete: string|null, onUpdate: string|null}>
     */
    public function unindexedForeignKeys(): array
    {
        $unindexed = [];

        foreach ($this->foreignKeys as $key) {
            if (! $this->isCoveredByAnIndex($key['columns'])) {
                $unindexed[] = $key;
            }
        }

        return $unindexed;
    }

    /**
     * @param  list<string>  $columns
     */
    private function isCoveredByAnIndex(array $columns): bool
    {
        if ($columns === []) {
            return false;
        }

        foreach ($this->indexes as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'columns' => $this->columns,
            'indexes' => $this->indexes,
            'foreignKeys' => $this->foreignKeys,
        ];
    }
}
