<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Graph\Graph;

/**
 * What is known about each event and listener, in one place, for every graph that shows one.
 *
 * The choreography tab is not the only place an event appears. A route graph reaches an event the
 * moment the request dispatches one, and until this existed the node stopped there: name, file,
 * nothing else. Measured on a real application, a single route showed eight events, one of which
 * sets off seven listeners and two of which set off nothing at all — and the graph said neither.
 *
 * So the facts are computed once and stamped onto whatever graph carries the node, rather than
 * assembled inside the tab that happens to be about events. One stamping point also means a new
 * fact reaches every graph at once, instead of reaching the tabs somebody remembered to edit.
 */
class EventFacts
{
    /** @var array<string, list<string>> event FQCN => listener FQCNs */
    private array $listeners = [];

    /**
     * @param  array<string, EventDefinition>  $events
     * @param  CallChainEdge[]  $listenerEdges
     */
    public function __construct(
        private readonly array $events,
        array $listenerEdges,
        private readonly QueueDeferral $deferral,
    ) {
        foreach ($listenerEdges as $edge) {
            if ($edge->type === 'listener') {
                $this->listeners[ltrim($edge->callerFqcn, '\\')][] = ltrim($edge->calleeFqcn, '\\');
            }
        }

        foreach ($this->listeners as $event => $listeners) {
            $this->listeners[$event] = array_values(array_unique($listeners));
        }
    }

    /** @return array<string, EventDefinition> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<string> */
    public function listenersFor(string $eventFqcn): array
    {
        return $this->listeners[ltrim($eventFqcn, '\\')] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function eventPayload(string $eventFqcn): array
    {
        $fqcn = ltrim($eventFqcn, '\\');
        $definition = $this->events[$fqcn] ?? new EventDefinition(fqcn: $fqcn);
        $listeners = $this->listenersFor($fqcn);

        return [
            ...$definition->toArray(),
            'listenerCount' => count($listeners),
            // The one thing worth saying about an event before anything else: whether firing it
            // does anything at all.
            'orphan' => $listeners === [],
            'observableBeforeCommit' => $this->deferral->observableBeforeCommit($definition->deferred, $listeners),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listenerPayload(string $listenerFqcn): array
    {
        $queued = $this->deferral->isQueued(ltrim($listenerFqcn, '\\'));

        return [
            'queued' => $queued,
            // Whether it waits for the commit is a property of the connection, not of the
            // listener, so it is resolved here rather than left to a reader who would have to go
            // and read queue.php to know.
            'deferred' => $queued && $this->deferral->queuedWorkIsDeferred(),
        ];
    }

    /**
     * Attach the facts to every event and listener node a graph already has.
     *
     * Only merges into nodes that exist; it never adds one. A route graph shows the events that
     * request dispatches and no others, and that is the right set — the question there is "what
     * does this request set off", not "what events exist".
     *
     * @return int nodes stamped
     */
    public function stamp(Graph $graph): int
    {
        $stamped = 0;

        foreach ($graph->nodes() as $node) {
            $fqcn = $node->data['fqcn'] ?? null;

            if (! is_string($fqcn) || $fqcn === '') {
                continue;
            }

            // Merged, not passed alone: `updateNodeData` replaces the node's whole data array,
            // so handing it one key drops the file path, the flow steps and everything else the
            // builder put there — silently, since a node with no file still renders.
            if ($node->type === 'event') {
                $graph->updateNodeData($node->id, [...$node->data, 'event' => $this->eventPayload($fqcn)]);
                $stamped++;
            } elseif ($node->type === 'listener') {
                $graph->updateNodeData($node->id, [...$node->data, 'listener' => $this->listenerPayload($fqcn)]);
                $stamped++;
            }
        }

        return $stamped;
    }
}
