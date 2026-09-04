<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

/**
 * What a build found on both sides of the reachability question: the roots, and the classes
 * no root arrives at.
 */
final class ReachabilityReport
{
    /**
     * @param  list<EntryPoint>  $entryPoints
     * @param  list<UnreachedClass>  $unreached
     * @param  int  $classesDeclared  classes found under the configured source paths
     * @param  int  $classesReached  of those, the ones a traced chain arrives at
     */
    public function __construct(
        public array $entryPoints,
        public array $unreached,
        public int $classesDeclared,
        public int $classesReached,
    ) {}

    /**
     * Entry points grouped by kind, in {@see EntryPoint::KIND_ORDER}, empty kinds omitted.
     *
     * @return array<string, list<EntryPoint>>
     */
    public function entryPointsByKind(): array
    {
        $byKind = [];
        foreach ($this->entryPoints as $entryPoint) {
            $byKind[$entryPoint->kind][] = $entryPoint;
        }

        $ordered = [];
        foreach (EntryPoint::KIND_ORDER as $kind) {
            if (isset($byKind[$kind])) {
                $ordered[$kind] = $byKind[$kind];
            }
        }
        foreach ($byKind as $kind => $entries) {
            $ordered[$kind] ??= $entries;
        }

        return $ordered;
    }

    /**
     * Unreached classes grouped by kind, largest group first — "17 jobs nothing dispatches"
     * is the sentence this tab exists to answer, and it should be the first thing on it.
     *
     * @param  bool  $tracerBlind  which half to return: the kinds the tracer can reach, or the
     *                             kinds it structurally cannot
     * @return array<string, list<UnreachedClass>>
     */
    public function unreachedByKind(bool $tracerBlind = false): array
    {
        $byKind = [];
        foreach ($this->unreached as $class) {
            if ($class->tracerBlind !== $tracerBlind) {
                continue;
            }
            $byKind[$class->kind][] = $class;
        }

        uasort($byKind, static function (array $a, array $b): int {
            return count($b) <=> count($a) ?: strcmp($a[0]->kind, $b[0]->kind);
        });

        return $byKind;
    }
}
