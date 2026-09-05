<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * A real throwaway Laravel-shaped tree with a real git history, mirroring
 * tests/Unit/ProjectAnalyzerGcTest.php's setup and tests/fixtures/cache-project's minimal
 * shape (composer.json PSR-4 map, one route, one controller) — churn needs real git commits
 * behind a real scan, which no static fixture alone can stand in for.
 */
beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'ChurnTest']]));

    $this->root = sys_get_temp_dir().'/brain-pa-churn-'.uniqid();
    mkdir($this->root.'/app/Http/Controllers', 0o777, true);
    mkdir($this->root.'/routes', 0o777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'name' => 'test/churn-project',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    file_put_contents($this->root.'/routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\OrderController;
        use Illuminate\Support\Facades\Route;

        Route::get('/orders', [OrderController::class, 'index']);
        PHP);

    $this->controllerPath = $this->root.'/app/Http/Controllers/OrderController.php';
    file_put_contents($this->controllerPath, <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class OrderController
        {
            public function index()
            {
                if (true) {
                    return 'a';
                } elseif (false) {
                    return 'b';
                }

                for ($i = 0; $i < 1; $i++) {
                    echo $i;
                }

                return 'c';
            }
        }
        PHP);

    exec('git init -q '.escapeshellarg($this->root));
    exec('git -C '.escapeshellarg($this->root).' config user.email test@example.com');
    exec('git -C '.escapeshellarg($this->root).' config user.name "Test User"');
    exec('git -C '.escapeshellarg($this->root).' config commit.gpgsign false');
    exec('git -C '.escapeshellarg($this->root).' add -A');
    exec('git -C '.escapeshellarg($this->root).' commit -q -m "Initial"');

    // A second commit to the controller, so commitCount is unambiguously > 1.
    file_put_contents($this->controllerPath, file_get_contents($this->controllerPath).PHP_EOL.'// touched'.PHP_EOL);
    exec('git -C '.escapeshellarg($this->root).' add -A');
    exec('git -C '.escapeshellarg($this->root).' commit -q -m "Touch controller"');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
    Container::setInstance(null);
});

it('ranks the controller file and stamps churn on its node, inside a subgraph, not only the full graph', function () {
    $result = (new ProjectAnalyzer)->analyze($this->root, function () {});

    $manifest = json_decode($result->manifestJson, true);

    expect($manifest)->toHaveKey('riskiestFiles')
        ->and($manifest['riskiestFiles'])->not->toBeEmpty();

    $entry = $manifest['riskiestFiles'][0];
    // Compared against the raw (non-realpath'd) path deliberately: GitChurnAnalyzer and
    // GraphBuilder both build data.file from $projectRoot as passed, with no normalization
    // of their own — realpath()-ing either side independently is exactly the trap
    // GraphProvenance's own doc comment warns about (e.g. macOS rewriting /var to
    // /private/var), and would make this assertion fail against a correctly-matching pair.
    expect($entry['file'])->toBe($this->controllerPath)
        ->and($entry['commitCount'])->toBe(2)
        ->and($entry['maxComplexity'])->toBeGreaterThan(1)
        ->and($entry['riskScore'])->toBe($entry['maxComplexity'] * 2);

    // The stamp must survive the split into per-tab subgraphs, not just exist on fullGraph —
    // this is the regression test for stamping before vs. after $this->graphSplitter->split().
    $stampedInASubgraph = false;
    foreach ($result->subgraphs as $subgraph) {
        foreach ($subgraph->nodes() as $node) {
            if (($node->data['file'] ?? null) === $entry['file'] && isset($node->data['churn'])) {
                $stampedInASubgraph = true;
                expect($node->data['churn']['commitCount'])->toBe(2)
                    ->and($node->data['churn']['lastAuthor'])->toBe('Test User');
            }
        }
    }
    expect($stampedInASubgraph)->toBeTrue();
});

it('produces no riskiestFiles when churn is disabled', function () {
    Container::getInstance()->instance('config', new Repository([
        'app' => ['name' => 'ChurnTest'],
        'laravel-brain' => ['churn' => ['enabled' => false]],
    ]));

    $result = (new ProjectAnalyzer)->analyze($this->root, function () {});
    $manifest = json_decode($result->manifestJson, true);

    expect($manifest)->not->toHaveKey('riskiestFiles');
});
