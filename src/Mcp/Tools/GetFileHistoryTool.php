<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Ai\MergedGraph;
use LaraMint\LaravelBrain\Analysis\GitHistoryInspector;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Returns who last committed a scanned file and the diff that commit introduced, by graph node id. Use this to see recent authorship/change context for a node before suggesting an edit to it.')]
#[IsReadOnly]
class GetFileHistoryTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['nodeId' => 'required|string']);
        $store = GraphStoreFactory::make();

        try {
            $graph = MergedGraph::load($store);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        $file = self::resolveFilePath($graph, $validated['nodeId']);
        if ($file === null) {
            return Response::error("No node found with id \"{$validated['nodeId']}\", or it has no associated file. Call brain_get_manifest or brain_get_graph to find valid node ids.");
        }

        $real = realpath($file);
        if ($real === false || ! is_file($real) || ! BrainController::isWithinProjectRoot($real, base_path())) {
            return Response::error("File not found on disk: {$file}");
        }

        $history = (new GitHistoryInspector)->lastCommit($real, base_path());

        return Response::structured(['history' => $history]);
    }

    /**
     * Pure, I/O-free core split out from handle() so it's testable without a scan on disk —
     * the same reasoning as {@see UsageFinder::findInGraph()} and
     * {@see GetRouteSecurityTool::filterRoutes()}.
     *
     * @param  array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    public static function resolveFilePath(array $graph, string $nodeId): ?string
    {
        foreach ($graph['nodes'] as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                $data = $node['data'] ?? null;
                $file = is_array($data) ? ($data['file'] ?? null) : null;

                return is_string($file) && $file !== '' ? $file : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nodeId' => $schema->string()
                ->description('The exact graph node id to get commit history for, e.g. "service::OrderService::place". Node ids come from brain_get_manifest, brain_get_subgraph, or brain_get_graph.')
                ->required(),
        ];
    }
}
