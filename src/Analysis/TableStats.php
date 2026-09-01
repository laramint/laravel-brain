<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * What the live database says about one table.
 *
 * Every figure is nullable and they are not all-or-nothing: `Schema::getTables()` gives a total
 * size on every driver Laravel supports, while the row count and the table/index split need
 * driver-specific SQL that only some of them can answer. A table can therefore arrive with a
 * total and nothing else, and that is a complete answer rather than a failed one.
 *
 * `rowsEstimated` exists because the honest row count is the cheap one. An exact `count(*)` is a
 * sequential scan on Postgres, and a scan of a few hundred models would spend minutes there; the
 * planner's own estimate is free and answers the question actually being asked — how much data
 * am I dealing with. The flag is carried so the UI can say "about" rather than imply a fact.
 */
final class TableStats
{
    public function __construct(
        public readonly string $table,
        public readonly ?int $rows = null,
        public readonly ?int $tableBytes = null,
        public readonly ?int $indexBytes = null,
        public readonly ?int $totalBytes = null,
        public readonly bool $rowsEstimated = true,
    ) {}

    /**
     * @return array{rows: int|null, tableBytes: int|null, indexBytes: int|null, totalBytes: int|null, rowsEstimated: bool}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'tableBytes' => $this->tableBytes,
            'indexBytes' => $this->indexBytes,
            'totalBytes' => $this->totalBytes,
            'rowsEstimated' => $this->rowsEstimated,
        ];
    }
}
