<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

class Graph
{
    /** @var array<string, Node> */
    private array $nodes = [];

    /** @var array<string, Edge> */
    private array $edges = [];

    /** @var array<string, true> "source|target" index for O(1) directed-edge lookups */
    private array $directedEdgeIndex = [];

    private array $meta = [];

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function getNode(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Replace the data array of an existing node (creates a new Node instance).
     */
    public function updateNodeData(string $id, array $data): void
    {
        if (! isset($this->nodes[$id])) {
            return;
        }
        $old = $this->nodes[$id];
        $this->nodes[$id] = new Node($old->id, $old->type, $old->label, $data);
    }

    public function addEdge(Edge $edge): void
    {
        $this->edges[$edge->id] = $edge;
        $this->directedEdgeIndex[$edge->source.'|'.$edge->target] = true;
    }

    public function hasDirectedEdge(string $source, string $target): bool
    {
        return isset($this->directedEdgeIndex[$source.'|'.$target]);
    }

    public function hasEdge(string $id): bool
    {
        return isset($this->edges[$id]);
    }

    public function setMeta(array $meta): void
    {
        $this->meta = $meta;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    public function edgeCount(): int
    {
        return count($this->edges);
    }

    /** @return Node[] */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return Edge[] */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * Nodes that no edge touches, counted by type.
     *
     * A node with no edge cannot be reached from any tab's seed, so it exists in the stored graph
     * and appears nowhere a reader will look. That is nearly always a wiring bug in whichever pass
     * created it rather than a fact about the application, which is why it is worth counting.
     *
     * @return array<string, int> node type => count, highest first
     */
    public function isolatedNodeCountsByType(): array
    {
        $touched = [];

        foreach ($this->edges as $edge) {
            $touched[$edge->source] = true;
            $touched[$edge->target] = true;
        }

        $counts = [];

        foreach ($this->nodes as $node) {
            if (! isset($touched[$node->id])) {
                $counts[$node->type] = ($counts[$node->type] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    public function toJson(): string
    {
        $meta = array_merge($this->meta, [
            'nodeCount' => $this->nodeCount(),
            'edgeCount' => $this->edgeCount(),
        ]);

        $json = json_encode([
            'meta' => $meta,
            'nodes' => $this->nodes(),
            'edges' => $this->edges(),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode graph to JSON: '.json_last_error_msg());
        }

        return $json;
    }
}
