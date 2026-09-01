<?php

use LaraMint\LaravelBrain\Analysis\FlowExtractor;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/** Flow steps for the first method of a class written inline. */
function flowFor(string $body, bool $relationsAutoloaded = false): array
{
    $parsed = (new PhpFileParser)->parseCode(<<<PHP
        <?php

        namespace App;

        class Subject
        {
            public function handle()
            {
        {$body}
            }
        }
        PHP);

    $found = null;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new class($found) extends NodeVisitorAbstract
    {
        public function __construct(private mixed &$found) {}

        public function enterNode(Node $node): ?int
        {
            if ($node instanceof Node\Stmt\ClassMethod && $this->found === null) {
                $this->found = $node;
            }

            return null;
        }
    });
    $traverser->traverse($parsed['ast'] ?? []);

    return $found === null ? [] : (new FlowExtractor($relationsAutoloaded))->extract($found, $parsed['useMap'] ?? []);
}

it('shows the work inside a DB::transaction, not just the wrapper', function () {
    // The reported bug: a method whose body is wrapped in a transaction charted as one step and
    // nothing else, so everything the transaction actually did was invisible.
    $steps = flowFor('        \DB::transaction(function () {
            $this->payer->charge();
            $this->ledger->record();
        });');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['label'])->toContain('transaction')
        ->and($steps[0]['body'] ?? [])->toHaveCount(2);

    $labels = array_column($steps[0]['body'], 'label');
    expect($labels[0])->toContain('charge')
        ->and($labels[1])->toContain('record');
});

it('marks a step carrying a body as the type the viewer descends into', function () {
    // Both viewer renderers read `body` only for the `loop` type — a `call` carrying one is
    // dropped silently, which is the difference between fixing this and appearing to.
    $steps = flowFor('        \DB::transaction(function () {
            $this->payer->charge();
        });');

    expect($steps[0]['type'])->toBe('loop');
});

it('descends into an arrow function too', function () {
    $steps = flowFor('        \Cache::remember("k", 60, fn () => $this->repo->load());');

    expect($steps[0]['body'] ?? [])->toHaveCount(1)
        ->and($steps[0]['body'][0]['label'])->toContain('load')
        // An implicit return, as extractFromClosure() reads the same shape.
        ->and($steps[0]['body'][0]['type'])->toBe('return');
});

it('leaves a call without a callback exactly as it was', function () {
    $steps = flowFor('        $this->payer->charge();');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['type'])->toBe('call')
        ->and($steps[0])->not->toHaveKey('body');
});

it('keeps the calls a closure makes visible to the flow, nested one level', function () {
    // A collection callback is the same shape and just as invisible before this.
    $steps = flowFor('        collect($rows)->each(function ($row) {
            $this->importer->import($row);
        });');

    expect($steps[0]['body'] ?? [])->toHaveCount(1)
        ->and($steps[0]['body'][0]['label'])->toContain('import');
});

it('surfaces an N+1 that was hidden inside a callback', function () {
    // A query in a foreach inside a callback was invisible, so the N+1 marker never reached it.
    // Callback bodies are walked as not-in-a-loop, so the flag can only come from the real
    // foreach nested within — a transaction is not itself a loop and is not counted as one.
    $steps = flowFor('        \DB::transaction(function () {
            foreach ($this->rows as $row) {
                \App\Models\Thing::find($row->id);
            }
        });');

    $inner = $steps[0]['body'][0] ?? [];
    expect($inner['type'])->toBe('loop')
        ->and($inner['label'])->toContain('foreach')
        ->and($inner['body'][0]['n1'] ?? false)->toBeTrue();
});

it('does not call a callback that merely contains a query an N+1', function () {
    $steps = flowFor('        \DB::transaction(function () {
            \App\Models\Thing::find(1);
        });');

    expect($steps[0]['n1'] ?? false)->toBeFalse()
        ->and($steps[0]['body'][0]['n1'] ?? false)->toBeFalse();
});

it('labels a closure by its signature rather than its whole body', function () {
    // The body used to be pretty-printed into the label. For a callback the body is charted
    // underneath the step anyway, so the label repeated it; and for a chain of callbacks each
    // level re-rendered everything inside it, which is what made deep nesting quadratic.
    $steps = flowFor('        $constraint = function ($query, $user) {
            $query->whereBelongsTo($user);
        };');

    expect($steps[0]['label'])->toBe('$constraint = function ($query, $user) {...}')
        ->and($steps[0]['label'])->not->toContain('whereBelongsTo');
});

it('labels an arrow function by its signature too', function () {
    $steps = flowFor('        $ids = $items->filter(fn ($item) => $item->id > 10);');

    expect($steps[0]['label'])->toContain('fn ($item) => ...')
        ->and($steps[0]['label'])->not->toContain('> 10');
});

it('stops descending into callbacks nested past any depth real code reaches', function () {
    // FlowExtractor runs over whatever is in the project, and a generated or pathological file
    // should not be able to take a scan down. Before the limit, 640 levels exhausted a 128 MB
    // memory limit inside the pretty printer; 320 took half a second for one file.
    $depth = 200;
    $inner = '$this->svc->leaf();';
    for ($i = 0; $i < $depth; $i++) {
        $inner = "\$this->svc->wrap(function () { {$inner} });";
    }

    $started = microtime(true);
    $steps = flowFor('        '.$inner);
    $elapsed = (microtime(true) - $started) * 1000;

    // It still charts, and it charts quickly — the guard truncates rather than throwing.
    expect($steps)->toHaveCount(1)->and($elapsed)->toBeLessThan(500);

    $levels = 0;
    $cursor = $steps;
    while (! empty($cursor[0]['body'])) {
        $levels++;
        $cursor = $cursor[0]['body'];
    }
    expect($levels)->toBeLessThanOrEqual(32)->and($levels)->toBeGreaterThan(1);
});

it('flags a relation read off the value a loop is iterating', function () {
    $steps = flowFor('        foreach ($orders as $order) {
            $order->customer;
        }');

    expect($steps[0]['n1'] ?? false)->toBeTrue();
});

it('follows the relation chain back to the loop value', function () {
    $steps = flowFor('        foreach ($orders as $order) {
            $order->customer->address;
        }');

    expect($steps[0]['n1'] ?? false)->toBeTrue();
});

it('does not call an ordinary collaborator call in a loop a query', function () {
    // `$this->service->handle()` reached the rule through the method-call chain, whose receiver
    // is a property fetch. Measured on a real application, treating every property fetch as a
    // query produced 28 of 33 markers — a pattern that appears in almost any loop.
    $steps = flowFor('        foreach ($rows as $row) {
            $this->releaseClaim->execute($row);
        }');

    expect($steps[0]['n1'] ?? false)->toBeFalse();
});

it('does not flag reading a property of something the loop does not bind', function () {
    $steps = flowFor('        foreach ($rows as $row) {
            $this->config->timeout;
        }');

    expect($steps[0]['n1'] ?? false)->toBeFalse();
});

it('still flags an explicit query in a loop, whatever it is called on', function () {
    // Narrowing the relation rule must not lose the case that was never in doubt.
    $steps = flowFor('        foreach ($ids as $id) {
            $warehouse = \App\Models\Warehouse::query()->find($id);
        }');

    expect($steps[0]['n1'] ?? false)->toBeTrue();
});

it('leaves a relation read alone when the application autoloads relations', function () {
    // Model::automaticallyEagerLoadRelationships() batches the whole collection on first touch,
    // so the per-iteration query the marker warns about does not happen.
    $steps = flowFor('        foreach ($orders as $order) {
            $order->customer;
        }', relationsAutoloaded: true);

    expect($steps[0]['n1'] ?? false)->toBeFalse();
});

it('still flags an explicit query even when relations are autoloaded', function () {
    // Autoloading batches relation access. It does not batch a query somebody wrote out.
    $steps = flowFor('        foreach ($ids as $id) {
            \App\Models\Warehouse::query()->find($id);
        }', relationsAutoloaded: true);

    expect($steps[0]['n1'] ?? false)->toBeTrue();
});

it('forgets a loop variable once the loop has ended', function () {
    // The extractor is reused across every method it charts, so a name left bound after its loop
    // closes goes on flagging reads of that name — in the next loop, and in the next method.
    $steps = flowFor('        foreach ($orders as $order) {
            $order->customer;
        }
        foreach ($rows as $row) {
            $order->customer;
        }');

    expect($steps[0]['n1'] ?? false)->toBeTrue()
        ->and($steps[1]['n1'] ?? false)->toBeFalse();
});

it('charts a cache call as its own step type, not an anonymous call', function () {
    // `call` and `assign` are both drawn as plain rectangles, so re-typing costs nothing and
    // buys the one distinction worth having on a chart: this step talks to the cache.
    $steps = flowFor('        \Cache::forget("users.index");');

    expect($steps[0]['type'])->toBe('cache')
        ->and($steps[0]['cache'])->toMatchArray([
            'kind' => 'invalidate',
            'method' => 'forget',
            'key' => 'users.index',
        ]);
});

it('charts an assignment from the cache as a cache step', function () {
    $steps = flowFor('        $users = \Cache::get("users.index");');

    expect($steps[0]['type'])->toBe('cache')
        ->and($steps[0]['label'])->toBe('$users = Cache::get("users.index")')
        ->and($steps[0]['cache']['kind'])->toBe('read');
});

it('leaves a returned cache read drawn as a return, with the details attached', function () {
    // `return` is drawn as a terminal in both renderers and reads as the end of the flow;
    // trading that shape for a colour would cost more than it buys.
    $steps = flowFor('        return \Cache::get("users.index");');

    expect($steps[0]['type'])->toBe('return')
        ->and($steps[0]['cache']['kind'])->toBe('read');
});

it('keeps the cache details on a remember() whose body it descends into', function () {
    // withCallbackBody() re-types the step to `loop`, the only type either renderer descends
    // into. The cache payload has to survive that, or the commonest cache call in Laravel is
    // the one call that never shows as one.
    $steps = flowFor('        \Cache::remember("users.index", 600, function () {
            return $this->repo->all();
        });');

    expect($steps[0]['type'])->toBe('loop')
        ->and($steps[0]['body'] ?? [])->toHaveCount(1)
        ->and($steps[0]['cache'])->toMatchArray(['kind' => 'read', 'method' => 'remember', 'ttl' => 600]);
});

it('leaves a step that touches no cache without a cache key', function () {
    $steps = flowFor('        $this->payer->charge();');

    expect($steps[0])->not->toHaveKey('cache');
});
