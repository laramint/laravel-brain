<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

use LaraMint\LaravelBrain\Analysis\ContainerBindingRegistry;
use LaraMint\LaravelBrain\Analysis\FacadeRegistry;
use LaraMint\LaravelBrain\Graph\Graph;

/**
 * The inverse of every other view Brain builds.
 *
 * The graph is grown forward from entry points, so it can only ever show what a traced call
 * chain happens to arrive at, and what it misses is invisible by construction — measured on
 * one application it held 45 of 211 event classes and 27 of 113 job classes, and nothing in
 * the interface said so. This walks the same graph from the same roots and then subtracts:
 * what is declared under the source paths, minus what the walk arrived at.
 *
 * The subtraction is only half the answer, and the smaller half. A class the container
 * resolves, a facade fronts, a config file names by string, or a reached class inherits from
 * is running in production and still falls out of that difference, because there is no call
 * for the tracer to follow. Those are found here too and attached to the class as
 * {@see UnreachedClass::$unfollowableReferences}, so the report can say "nothing reaches this
 * from a traced entry point" and show the reader exactly why that is not the same sentence as
 * "this is dead".
 */
final class ReachabilityAnalyzer
{
    /**
     * @param  list<EntryPoint>  $entryPoints
     */
    public function analyze(
        Graph $graph,
        array $entryPoints,
        ClassInventory $classes,
        ?ContainerBindingRegistry $bindings = null,
        ?FacadeRegistry $facades = null,
        ?ClassStringIndex $sourceReferences = null,
        ?ClassStringIndex $configReferences = null,
    ): ReachabilityReport {
        $sourceReferences ??= ClassStringIndex::empty();
        $configReferences ??= ClassStringIndex::empty();

        $reached = $this->reachedFqcns($graph, $entryPoints);
        $declared = $classes->all();

        $boundNames = $this->boundNames($bindings, $facades);
        $inherited = $this->inheritedByReached($declared, $reached);

        $unreached = [];
        foreach ($declared as $fqcn => $class) {
            if (isset($reached[$fqcn])) {
                continue;
            }

            $references = [];
            if (isset($boundNames[$fqcn])) {
                $references[] = $boundNames[$fqcn];
            }
            if ($configReferences->hasReferenceTo($fqcn, $class->file)) {
                $references[] = UnreachedClass::REFERENCE_CONFIG;
            }
            if (isset($inherited[$fqcn])) {
                $references[] = UnreachedClass::REFERENCE_INHERITED;
            }
            if ($sourceReferences->hasReferenceTo($fqcn, $class->file)) {
                $references[] = UnreachedClass::REFERENCE_CLASS_STRING;
            }

            $unreached[] = new UnreachedClass(
                fqcn: $fqcn,
                file: $class->file,
                kind: $class->kind,
                unfollowableReferences: $references,
                tracerBlind: ClassInventory::isTracerBlind($class->kind),
            );
        }

        return new ReachabilityReport(
            entryPoints: $entryPoints,
            unreached: $unreached,
            classesDeclared: count($declared),
            classesReached: count($declared) - count($unreached),
        );
    }

    /**
     * Every application FQCN a forward walk from the entry points arrives at.
     *
     * Seeds come from two places, because neither alone finds every root. An entry point with
     * a signature — a route, a command, a channel, a schedule entry — is found by the node id
     * built from that signature, since a closure route has no class to look up. An entry point
     * that is only a class — a queued listener above all — is found by matching the FQCN
     * against every node carrying it, since its node id is a slug of the class and the method
     * that nothing outside GraphBuilder can reconstruct.
     *
     * The walk then follows every edge in the graph, including the two {@see
     * \LaraMint\LaravelBrain\Graph\GraphSplitter} drops when it cuts per-route tabs: those are
     * dropped there to stop a shared controller fanning a tab out to its sibling actions,
     * which is a rendering concern and not a second definition of what reaches what.
     *
     * An entry point's own class counts as reached without needing a node at all. A
     * `$schedule->job(RebuildIndex::class)` entry builds a node keyed by a hash of the entry
     * and carries the class only as a string; if nothing else dispatches that job, no node
     * anywhere is keyed by it. It runs every hour — a root, not a finding.
     *
     * @param  list<EntryPoint>  $entryPoints
     * @return array<string, true>
     */
    private function reachedFqcns(Graph $graph, array $entryPoints): array
    {
        $adjacency = [];
        foreach ($graph->edges() as $edge) {
            $adjacency[$edge->source][] = $edge->target;
        }

        $nodesByFqcn = [];
        foreach ($graph->nodes() as $node) {
            foreach (['fqcn', 'class'] as $key) {
                $value = $node->data[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $nodesByFqcn[ltrim($value, '\\')][] = $node->id;
                }
            }
        }

        $reached = [];
        $seeds = [];
        foreach ($entryPoints as $entryPoint) {
            foreach ($entryPoint->nodeIds as $nodeId) {
                $seeds[] = $nodeId;
            }
            if ($entryPoint->fqcn === '') {
                continue;
            }
            $fqcn = ltrim($entryPoint->fqcn, '\\');
            $reached[$fqcn] = true;
            foreach ($nodesByFqcn[$fqcn] ?? [] as $nodeId) {
                $seeds[] = $nodeId;
            }
        }

        $visited = [];
        while ($seeds !== []) {
            $id = array_pop($seeds);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $node = $graph->getNode($id);
            if ($node !== null) {
                foreach (['fqcn', 'class'] as $key) {
                    $value = $node->data[$key] ?? null;
                    if (is_string($value) && $value !== '') {
                        $reached[ltrim($value, '\\')] = true;
                    }
                }
            }

            foreach ($adjacency[$id] ?? [] as $neighbour) {
                if (! isset($visited[$neighbour])) {
                    $seeds[] = $neighbour;
                }
            }
        }

        return $reached;
    }

    /**
     * FQCNs the container or a facade names, mapped to the reference kind to report.
     *
     * Both sides of a binding are recorded: the abstract because `app(Contract::class)`
     * resolves through it, and the concrete because that is the class that actually runs and
     * the one a reader would otherwise be told nothing reaches.
     *
     * @return array<string, string>
     */
    private function boundNames(?ContainerBindingRegistry $bindings, ?FacadeRegistry $facades): array
    {
        $named = [];

        foreach (($bindings?->all() ?? []) as $record) {
            $named[ltrim($record->abstractFqcn, '\\')] = UnreachedClass::REFERENCE_CONTAINER_BINDING;
            if ($record->concreteFqcn !== null && $record->concreteFqcn !== '') {
                $named[ltrim($record->concreteFqcn, '\\')] = UnreachedClass::REFERENCE_CONTAINER_BINDING;
            }
        }

        foreach (($facades?->all() ?? []) as $record) {
            $named[ltrim($record->facadeFqcn, '\\')] = UnreachedClass::REFERENCE_FACADE;
            if ($record->concreteFqcn !== null && $record->concreteFqcn !== '') {
                $named[ltrim($record->concreteFqcn, '\\')] = UnreachedClass::REFERENCE_FACADE;
            }
        }

        return $named;
    }

    /**
     * Classes a *reached* class extends, implements or uses as a trait.
     *
     * A base class carrying the work its subclasses inherit is reached in every sense a
     * reader cares about, and the tracer records a hop to it only where a call happens to
     * resolve there. This is the cheap, exact version of that: the inventory already knows
     * every declaration's parents, interfaces and traits.
     *
     * @param  array<string, DeclaredClass>  $declared
     * @param  array<string, true>  $reached
     * @return array<string, true>
     */
    private function inheritedByReached(array $declared, array $reached): array
    {
        $inherited = [];

        foreach ($declared as $fqcn => $class) {
            if (! isset($reached[$fqcn])) {
                continue;
            }
            foreach (array_merge([$class->parent], $class->interfaces, $class->traits) as $ancestor) {
                if ($ancestor !== '') {
                    $inherited[ltrim($ancestor, '\\')] = true;
                }
            }
        }

        return $inherited;
    }
}
