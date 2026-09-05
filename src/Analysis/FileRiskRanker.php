<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Analysis\Incremental\GraphProvenance;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * Combines per-file commit frequency with each file's own most complex method into a single
 * ranking — the "code as a crime scene" hotspot technique: complexity alone says a method is
 * hard to read, commit frequency alone says a file is popular, and the two together say where
 * the next bug is likeliest.
 *
 * Also stamps `data.churn` onto every node whose file has churn data, independent of the
 * ranking — a node's own detail view can show its file's git activity even when that file's
 * complexity isn't high enough to make the ranked list.
 */
class FileRiskRanker
{
    /**
     * @param  array<string, array{commitCount: int, lastChangedAt: string}>  $churnByFile
     * @return list<array{file: string, commitCount: int, lastChangedAt: string, maxComplexity: int, riskScore: int}>
     *                                                                                                                sorted by riskScore descending, at most $limit long
     */
    public function apply(Graph $graph, array $churnByFile, int $limit): array
    {
        $byFile = GraphProvenance::of($graph)->byFile;
        $ranked = [];

        foreach ($byFile as $file => $ids) {
            if ($file === '' || ! isset($churnByFile[$file])) {
                continue;
            }

            $churn = $churnByFile[$file];
            $maxComplexity = 0;

            foreach ($ids['nodes'] as $nodeId) {
                $node = $graph->getNode($nodeId);
                if ($node === null) {
                    continue;
                }

                $graph->updateNodeData($nodeId, [...$node->data, 'churn' => $churn]);

                $cc = $node->data['metrics']['cyclomaticComplexity'] ?? null;
                if (is_int($cc) && $cc > $maxComplexity) {
                    $maxComplexity = $cc;
                }
            }

            if ($maxComplexity > 0) {
                $ranked[] = [
                    'file' => $file,
                    'commitCount' => $churn['commitCount'],
                    'lastChangedAt' => $churn['lastChangedAt'],
                    'maxComplexity' => $maxComplexity,
                    'riskScore' => $maxComplexity * $churn['commitCount'],
                ];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['riskScore'] <=> $a['riskScore']);

        return array_slice($ranked, 0, $limit);
    }
}
