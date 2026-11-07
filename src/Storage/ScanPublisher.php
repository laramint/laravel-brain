<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Storage;

use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphSplitter;

/**
 * Writes one scan's output to a store. The CLI scan, the viewer's re-scan button and the MCP
 * rescan tool all publish through here, so the stored manifest has the same shape whichever of
 * them ran.
 */
final class ScanPublisher
{
    /**
     * The flags {@see GraphSplitter} encodes the manifest with, so a decorated manifest is
     * stored in the same format as an undecorated one.
     */
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param  array<string, Graph>  $subgraphs  tabId => subgraph
     */
    public static function publish(GraphStore $store, string $manifestJson, array $subgraphs): void
    {
        $store->ensureSchema();

        $store->putManifest(self::decorateManifest($manifestJson, $store->getManifest()));

        foreach ($subgraphs as $tabId => $subgraph) {
            $store->putSubgraph((string) $tabId, $subgraph->toJson());
        }

        // Ordering matters: the manifest above is what the viewer indexes tabs from, and it
        // is written unconditionally, so pruning to the same set that produced it can only
        // remove blobs nothing can address. Moving either out of this block breaks that.
        $store->pruneSubgraphsExcept(array_map('strval', array_keys($subgraphs)));
    }

    /**
     * Decorate each manifest tab with a changeStatus (new/changed/unchanged) relative to the
     * previous scan, and record the previous scan timestamp. The viewer's "Recent" list is
     * built from these two fields alone.
     *
     * Fingerprint = nodeCount + edgeCount + riskLevel per tab.
     */
    public static function decorateManifest(string $newManifestJson, ?string $previousManifestJson): string
    {
        $new = json_decode($newManifestJson, true);

        if (! is_array($new) || ! isset($new['tabs']) || ! is_array($new['tabs'])) {
            return $newManifestJson;
        }

        $prevFingerprints = [];
        $prevAnalyzedAt = null;

        if ($previousManifestJson !== null) {
            $prev = json_decode($previousManifestJson, true);
            if (is_array($prev)) {
                $prevAnalyzedAt = $prev['analyzedAt'] ?? null;
                foreach (($prev['tabs'] ?? []) as $tab) {
                    if (isset($tab['id'])) {
                        $prevFingerprints[(string) $tab['id']] = self::tabFingerprint($tab);
                    }
                }
            }
        }

        foreach ($new['tabs'] as &$tab) {
            $id = isset($tab['id']) ? (string) $tab['id'] : null;

            if ($previousManifestJson === null || $id === null) {
                $tab['changeStatus'] = 'unchanged';

                continue;
            }

            if (! array_key_exists($id, $prevFingerprints)) {
                $tab['changeStatus'] = 'new';
            } elseif ($prevFingerprints[$id] !== self::tabFingerprint($tab)) {
                $tab['changeStatus'] = 'changed';
            } else {
                $tab['changeStatus'] = 'unchanged';
            }
        }
        unset($tab);

        if ($prevAnalyzedAt !== null) {
            $new['previousAnalyzedAt'] = $prevAnalyzedAt;
        }

        return json_encode($new, self::JSON_FLAGS) ?: $newManifestJson;
    }

    /**
     * @param  array<string, mixed>  $tab
     */
    private static function tabFingerprint(array $tab): string
    {
        return ($tab['nodeCount'] ?? 0).':'.($tab['edgeCount'] ?? 0).':'.($tab['riskLevel'] ?? 'none');
    }
}
