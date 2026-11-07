<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;
use LaraMint\LaravelBrain\Storage\DatabaseGraphStore;
use LaraMint\LaravelBrain\Storage\FileGraphStore;
use LaraMint\LaravelBrain\Storage\GraphStore;
use LaraMint\LaravelBrain\Storage\ScanPublisher;

function publisherStore(): FileGraphStore
{
    $dir = sys_get_temp_dir().'/lb-publish-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return new FileGraphStore($dir);
}

/**
 * A database store with no table yet, the state of a first scan on the database driver. Built
 * without ensureSchema(), so the publisher has to create the table itself.
 */
function publisherDatabaseStore(): DatabaseGraphStore
{
    $container = new Container;
    Container::setInstance($container);

    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();

    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);

    return new DatabaseGraphStore;
}

/** @param array<string, array{0: int, 1: int, 2?: string}> $tabs tabId => [nodeCount, edgeCount, riskLevel] */
function publisherManifest(string $analyzedAt, array $tabs): string
{
    $entries = [];
    foreach ($tabs as $id => $tab) {
        $entries[] = ['id' => $id, 'label' => '/'.$id, 'nodeCount' => $tab[0], 'edgeCount' => $tab[1], 'riskLevel' => $tab[2] ?? 'none'];
    }

    return (string) json_encode(['project' => 'App', 'analyzedAt' => $analyzedAt, 'tabs' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/** @param string[] $tabIds */
function publisherSubgraphs(array $tabIds): array
{
    $subgraphs = [];
    foreach ($tabIds as $id) {
        $graph = new Graph;
        $graph->addNode(new Node("route::GET::/{$id}", 'route', "GET /{$id}"));
        $subgraphs[$id] = $graph;
    }

    return $subgraphs;
}

function publishedTabs(GraphStore $store): array
{
    $statuses = [];
    foreach (json_decode((string) $store->getManifest(), true)['tabs'] as $tab) {
        $statuses[$tab['id']] = $tab['changeStatus'] ?? null;
    }

    return $statuses;
}

it('marks every tab unchanged on the first scan', function () {
    $store = publisherStore();

    ScanPublisher::publish($store, publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2]]), publisherSubgraphs(['orders']));

    expect(publishedTabs($store))->toBe(['orders' => 'unchanged'])
        ->and(json_decode((string) $store->getManifest(), true))->not->toHaveKey('previousAnalyzedAt');
});

it('compares a scan with the one the store already holds', function () {
    // The viewer's "Recent" list shows exactly the tabs marked new or changed, measured from
    // previousAnalyzedAt. Without this, a scan that is not the viewer's own button left it empty.
    $store = publisherStore();
    ScanPublisher::publish(
        $store,
        publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2], 'users' => [3, 2], 'refunds' => [3, 2], 'login' => [3, 2], 'legacy' => [2, 1]]),
        publisherSubgraphs(['orders', 'users', 'refunds', 'login', 'legacy']),
    );

    // Each counts toward the fingerprint on its own: `refunds` changes only its edge count, and
    // `login` only its risk level.
    ScanPublisher::publish(
        $store,
        publisherManifest('2026-01-02T00:00:00Z', ['orders' => [4, 3], 'users' => [3, 2], 'refunds' => [3, 3], 'login' => [3, 2, 'high'], 'invoices' => [2, 1]]),
        publisherSubgraphs(['orders', 'users', 'refunds', 'login', 'invoices']),
    );

    $manifest = json_decode((string) $store->getManifest(), true);

    expect(publishedTabs($store))->toBe(['orders' => 'changed', 'users' => 'unchanged', 'refunds' => 'changed', 'login' => 'changed', 'invoices' => 'new'])
        ->and($manifest['previousAnalyzedAt'])->toBe('2026-01-01T00:00:00Z')
        ->and($store->subgraphIds())->toEqualCanonicalizing(['orders', 'users', 'refunds', 'login', 'invoices']);
});

it('compares scans on the database driver, starting without a table', function () {
    $store = publisherDatabaseStore();

    ScanPublisher::publish($store, publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2]]), publisherSubgraphs(['orders']));
    ScanPublisher::publish($store, publisherManifest('2026-01-02T00:00:00Z', ['orders' => [4, 3], 'invoices' => [2, 1]]), publisherSubgraphs(['orders', 'invoices']));

    expect(publishedTabs($store))->toBe(['orders' => 'changed', 'invoices' => 'new'])
        ->and(json_decode((string) $store->getManifest(), true)['previousAnalyzedAt'])->toBe('2026-01-01T00:00:00Z')
        ->and($store->subgraphIds())->toEqualCanonicalizing(['orders', 'invoices']);
});

it('stores the decorated manifest in the format the splitter writes', function () {
    $store = publisherStore();

    ScanPublisher::publish($store, publisherManifest('2026-01-01T00:00:00Z', ['orders' => [3, 2]]), publisherSubgraphs(['orders']));

    expect($store->getManifest())->toContain('"label": "/orders"');
});

it('leaves the manifest to the publisher alone', function () {
    // Each entry point used to write the manifest itself, and only one of the three decorated
    // it. Any other caller of putManifest() would bring that back.
    $root = str_replace('\\', '/', dirname(__DIR__, 2).'/src');
    $writers = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (str_ends_with($path, '.php') && ! str_starts_with($path, $root.'/Storage/')
            && str_contains((string) file_get_contents($path), '->putManifest(')) {
            $writers[] = substr($path, strlen($root) + 1);
        }
    }

    expect($writers)->toBe([]);
});
