<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description("Lists the files most worth a closer look: ranked by recent commit frequency x that file's single most complex method (riskScore = maxComplexity x commitCount). Different from the complexity hotspots in brain_get_context/brain_get_agent_rules, which rank individual methods by complexity alone with no notion of how often they change.")]
#[IsReadOnly]
class GetRiskiestFilesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $store = GraphStoreFactory::make();

        if (! $store->hasManifest()) {
            return Response::error('No scan data found — call brain_rescan first.');
        }

        $manifest = json_decode((string) $store->getManifest(), true);
        if (! is_array($manifest)) {
            return Response::error('Stored manifest could not be read.');
        }

        $limitRaw = $request->get('limit');
        $limit = is_numeric($limitRaw) ? max(1, (int) $limitRaw) : 20;

        $files = self::topFiles($manifest, $limit);

        return Response::structured(['files' => $files, 'count' => count($files)]);
    }

    /**
     * Pure, I/O-free core split out from handle() so it's testable without a scan on disk —
     * the same reasoning as {@see GetRouteSecurityTool::filterRoutes()}.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    public static function topFiles(array $manifest, int $limit): array
    {
        $files = $manifest['riskiestFiles'] ?? [];

        return is_array($files) ? array_slice(array_values($files), 0, $limit) : [];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of files to return, highest risk first. Defaults to 20.'),
        ];
    }
}
