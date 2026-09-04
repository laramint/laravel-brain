<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Schema findings, in the shape the graph already uses for security findings.
 *
 * Deliberately the same payload — `exposure`, `riskLevel`, `issues[]` — so a model carrying one
 * lights up the same badge, the same Risks tab and the same colour a route without authentication
 * does. A reader has one place to look for "something here needs attention" rather than two.
 *
 * The severity is `medium` and stays there. A missing index is a real defect and a slow one, but
 * it is not an unauthenticated write, and putting the two at the same level would teach people to
 * skim the badge that exists to stop them skimming.
 */
class SchemaIssueBuilder
{
    /**
     * The finding payload for one table, or null when the table is clean.
     *
     * @return array{exposure: string, riskLevel: string, issues: list<array<string, mixed>>}|null
     */
    public function forTable(TableSchema $schema): ?array
    {
        $issues = [];

        foreach ($schema->unindexedForeignKeys() as $key) {
            $columns = implode(', ', $key['columns']);
            $target = $key['foreignTable'] !== '' ? " → `{$key['foreignTable']}`" : '';

            $issues[] = (new SecurityIssue(
                type: 'MISSING_FK_INDEX',
                severity: 'medium',
                message: "Foreign key `{$columns}`{$target} has no index. "
                    ."Every join, `whereHas` and cascading delete through it scans `{$schema->table}` in full.",
            ))->toArray();
        }

        if ($issues === []) {
            return null;
        }

        return [
            // Exposure is a route's word for who can reach it, and a table has no answer to that.
            // The key is carried because the panel reads it; the value says so plainly rather than
            // borrowing a level that would mean something it does not.
            'exposure' => 'n/a',
            'riskLevel' => 'medium',
            'issues' => $issues,
        ];
    }
}
