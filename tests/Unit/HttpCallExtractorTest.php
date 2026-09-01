<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\FlowExtractor;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Every outgoing call found in a method body, flattened out of the flow steps the same way
 * GraphBuilder flattens them — the calls hang off statements, so a test that only looked at the
 * top level would be blind to the ones inside a loop or an `if`.
 */
function httpCallsInMethodBody(string $body, string $imports = ''): array
{
    $parsed = (new PhpFileParser)->parseCode(<<<PHP
        <?php

        namespace App;

        {$imports}

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

    $steps = $found === null ? [] : (new FlowExtractor)->extract($found, $parsed['useMap'] ?? []);

    $flatten = function (array $steps) use (&$flatten): array {
        $calls = [];
        foreach ($steps as $step) {
            foreach (($step['http'] ?? []) as $call) {
                $calls[] = $call;
            }
            foreach (['then', 'else', 'body'] as $branch) {
                $calls = array_merge($calls, $flatten($step[$branch] ?? []));
            }
        }

        return $calls;
    };

    return $flatten($steps);
}

/** The Http facade, written the way an application writes it. */
function httpCallsUsingFacade(string $body): array
{
    return httpCallsInMethodBody($body, 'use Illuminate\Support\Facades\Http;');
}

describe('Laravel HTTP client', function () {
    it('reports the verb and the literal URL of a facade call', function () {
        $calls = httpCallsUsingFacade("        \\Illuminate\\Support\\Facades\\Http::get('https://api.example.test/orders');");

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['client'])->toBe('laravel')
            ->and($calls[0]['method'])->toBe('GET')
            ->and($calls[0]['url'])->toBe('https://api.example.test/orders')
            ->and($calls[0]['host'])->toBe('api.example.test')
            ->and($calls[0]['urlSource'])->toBe('literal');
    });

    it('sees through the builder to the verb, and keeps what the builder declared', function () {
        $calls = httpCallsUsingFacade(
            "        Http::withToken('t')->withHeaders([])->retry(3, 250)->timeout(5)->post('https://api.stripe.test/v1/charges', []);"
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('POST')
            ->and($calls[0]['host'])->toBe('api.stripe.test')
            ->and($calls[0]['timeout'])->toBe(5.0)
            ->and($calls[0]['retryTimes'])->toBe(3)
            ->and($calls[0]['retrySleep'])->toBe(250);
    });

    it('leaves retry and timeout null when the code declares neither', function () {
        // The absence is the finding: a caller with no timeout waits as long as the third party
        // takes, and the panel says so only if this stays distinguishable from "we could not read it".
        $calls = httpCallsUsingFacade("        Http::get('https://api.example.test/orders');");

        expect($calls[0]['timeout'])->toBeNull()
            ->and($calls[0]['retryTimes'])->toBeNull()
            ->and($calls[0]['retrySleep'])->toBeNull();
    });

    it('folds a literal base URL into the path the verb was given', function () {
        $calls = httpCallsUsingFacade("        Http::baseUrl('https://api.example.test/v2')->get('/orders');");

        expect($calls[0]['url'])->toBe('https://api.example.test/v2/orders')
            ->and($calls[0]['host'])->toBe('api.example.test')
            ->and($calls[0]['urlSource'])->toBe('literal');
    });

    it('names the config key when that is where the address comes from', function () {
        $calls = httpCallsUsingFacade("        Http::baseUrl(config('services.allegro.url'))->get('/orders');");

        expect($calls[0]['urlSource'])->toBe('config')
            ->and($calls[0]['configKey'])->toBe('services.allegro.url')
            ->and($calls[0]['url'])->toBe('/orders');
    });

    it('keeps the readable prefix of a constructed URL and marks it as partly computed', function () {
        $calls = httpCallsUsingFacade("        Http::get('https://api.example.test/orders/'.\$id);");

        expect($calls[0]['url'])->toBe('https://api.example.test/orders/…')
            ->and($calls[0]['host'])->toBe('api.example.test')
            ->and($calls[0]['urlSource'])->toBe('constructed');
    });

    it('reads an interpolated URL the same way as a concatenated one', function () {
        $calls = httpCallsUsingFacade('        Http::get("https://api.example.test/orders/{$id}");');

        expect($calls[0]['url'])->toBe('https://api.example.test/orders/…')
            ->and($calls[0]['urlSource'])->toBe('constructed');
    });

    it('claims nothing about a URL held in a variable', function () {
        $calls = httpCallsUsingFacade('        Http::get($endpoint);');

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('GET')
            ->and($calls[0]['url'])->toBe('')
            ->and($calls[0]['host'])->toBe('')
            ->and($calls[0]['urlSource'])->toBe('dynamic');
    });

    it('takes the verb out of send()', function () {
        $calls = httpCallsUsingFacade("        Http::send('PUT', 'https://api.example.test/orders/1');");

        expect($calls[0]['method'])->toBe('PUT')
            ->and($calls[0]['url'])->toBe('https://api.example.test/orders/1');
    });

    it('follows a pending request parked in a variable', function () {
        $calls = httpCallsUsingFacade(
            "        \$request = Http::timeout(9)->baseUrl('https://api.example.test');\n".
            "        \$request->delete('/orders/1');"
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('DELETE')
            ->and($calls[0]['url'])->toBe('https://api.example.test/orders/1')
            ->and($calls[0]['timeout'])->toBe(9.0);
    });

    it('reports a pool as the one concurrent call it is', function () {
        // The verbs live on a $pool object inside the closure, and counting both the pool and
        // them would report two requests twice over.
        $calls = httpCallsUsingFacade(
            "        Http::pool(fn (\$pool) => [\$pool->get('https://a.test'), \$pool->get('https://b.test')]);"
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['async'])->toBeTrue()
            ->and($calls[0]['method'])->toBe('');
    });

    it('ignores facade calls that are not requests', function () {
        $calls = httpCallsUsingFacade("        Http::fake();\n        Http::preventStrayRequests();");

        expect($calls)->toBe([]);
    });

    it('does not mistake an ordinary get() for a request', function () {
        // `$repository->get()` is the single most common method name in a Laravel application;
        // matching on the verb alone would report every one of them as a third-party call.
        $calls = httpCallsUsingFacade("        \$this->repository->get('orders');");

        expect($calls)->toBe([]);
    });
});

describe('Guzzle', function () {
    it('reports a call on a client constructed in the same method', function () {
        $calls = httpCallsInMethodBody(
            "        \$client = new Client(['base_uri' => 'https://ledger.test', 'timeout' => 2.5]);\n".
            "        \$client->request('POST', '/v2/entries');",
            'use GuzzleHttp\Client;'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['client'])->toBe('guzzle')
            ->and($calls[0]['method'])->toBe('POST')
            ->and($calls[0]['url'])->toBe('https://ledger.test/v2/entries')
            ->and($calls[0]['timeout'])->toBe(2.5);
    });

    it('reports an inline client and marks the async verbs async', function () {
        $calls = httpCallsInMethodBody(
            "        (new Client)->getAsync('https://ledger.test/v2/balance');",
            'use GuzzleHttp\Client;'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('GET')
            ->and($calls[0]['async'])->toBeTrue();
    });

    it('prefers a per-request timeout over the client default', function () {
        $calls = httpCallsInMethodBody(
            "        \$client = new Client(['timeout' => 30]);\n".
            "        \$client->get('https://ledger.test/v2/balance', ['timeout' => 2]);",
            'use GuzzleHttp\Client;'
        );

        expect($calls[0]['timeout'])->toBe(2.0);
    });

    it('follows a client assigned to a property of this class', function () {
        $calls = httpCallsInMethodBody(
            "        \$this->client = new Client(['base_uri' => 'https://ledger.test']);\n".
            "        \$this->client->post('/v2/entries');",
            'use GuzzleHttp\Client;'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['url'])->toBe('https://ledger.test/v2/entries');
    });

    it('says nothing about a client it never saw constructed', function () {
        // A constructor-injected client is invisible from a single method AST, and guessing from
        // the variable name would report every `$client->get()` in the project.
        $calls = httpCallsInMethodBody("        \$this->client->get('https://ledger.test/v2/balance');");

        expect($calls)->toBe([]);
    });
});

describe('the lower-level escape hatches', function () {
    it('reports curl_exec with what the handle was told', function () {
        $calls = httpCallsInMethodBody(
            "        \$ch = curl_init('https://legacy.test/soap');\n".
            "        curl_setopt(\$ch, CURLOPT_POST, true);\n".
            "        curl_setopt(\$ch, CURLOPT_TIMEOUT, 4);\n".
            '        $body = curl_exec($ch);'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['client'])->toBe('curl')
            ->and($calls[0]['method'])->toBe('POST')
            ->and($calls[0]['url'])->toBe('https://legacy.test/soap')
            ->and($calls[0]['timeout'])->toBe(4.0);
    });

    it('takes the URL from a later curl_setopt as readily as from curl_init', function () {
        $calls = httpCallsInMethodBody(
            "        \$ch = curl_init();\n".
            "        curl_setopt(\$ch, CURLOPT_URL, 'https://legacy.test/rpc');\n".
            '        curl_exec($ch);'
        );

        expect($calls[0]['url'])->toBe('https://legacy.test/rpc');
    });

    it('reports file_get_contents on a visible http URL', function () {
        $calls = httpCallsInMethodBody("        \$json = file_get_contents('https://raw.test/data.json');");

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['client'])->toBe('stream')
            ->and($calls[0]['method'])->toBe('GET')
            ->and($calls[0]['host'])->toBe('raw.test');
    });

    it('leaves file_get_contents alone when nothing proves it is a URL', function () {
        // Far more of these are reads off disk, and a wrong "this calls a third party" costs more
        // than a missed one.
        $calls = httpCallsInMethodBody(
            "        \$a = file_get_contents(\$path);\n".
            "        \$b = file_get_contents(storage_path('app/data.json'));"
        );

        expect($calls)->toBe([]);
    });
});

describe('where the calls are found', function () {
    it('finds a call inside a loop', function () {
        $calls = httpCallsUsingFacade(
            "        foreach (\$ids as \$id) {\n".
            "            Http::get('https://api.example.test/orders/'.\$id);\n".
            '        }'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['host'])->toBe('api.example.test');
    });

    it('finds a call inside a closure passed to another call', function () {
        $calls = httpCallsUsingFacade(
            "        retry(3, function () {\n".
            "            Http::get('https://api.example.test/orders');\n".
            '        });'
        );

        expect($calls)->toHaveCount(1);
    });

    it('counts a call in a closure once, not once per enclosing step', function () {
        // The closure body is charted as its own steps, so scanning the wrapper's expression and
        // the body both would double every retried or transacted request in the project.
        $calls = httpCallsUsingFacade(
            "        \$result = retry(3, function () {\n".
            "            return Http::get('https://api.example.test/orders');\n".
            '        });'
        );

        expect($calls)->toHaveCount(1);
    });

    it('finds a call used as a condition', function () {
        $calls = httpCallsUsingFacade(
            "        if (Http::get('https://api.example.test/ping')->failed()) {\n".
            "            return 'down';\n".
            '        }'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['method'])->toBe('GET');
    });

    it('finds a call in a return statement', function () {
        $calls = httpCallsUsingFacade("        return Http::get('https://api.example.test/orders')->json();");

        expect($calls)->toHaveCount(1);
    });

    it('does not carry a client from one method into the next', function () {
        // FlowExtractor is reused across every method of a project; a `$client` remembered from
        // a previous method would attribute the wrong host — or invent a call outright.
        $parsed = (new PhpFileParser)->parseCode(<<<'PHP'
            <?php

            namespace App;

            use GuzzleHttp\Client;

            class Subject
            {
                public function first()
                {
                    $client = new Client(['base_uri' => 'https://ledger.test']);
                    $client->get('/v2/balance');
                }

                public function second()
                {
                    $client->get('/v2/balance');
                }
            }
            PHP);

        $methods = [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($methods) extends NodeVisitorAbstract
        {
            public function __construct(private array &$methods) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $this->methods[$node->name->toString()] = $node;
                }

                return null;
            }
        });
        $traverser->traverse($parsed['ast'] ?? []);

        $extractor = new FlowExtractor;
        $first = $extractor->extract($methods['first'], $parsed['useMap']);
        $second = $extractor->extract($methods['second'], $parsed['useMap']);

        expect(array_filter($first, fn ($s) => isset($s['http'])))->not->toBeEmpty()
            ->and(array_filter($second, fn ($s) => isset($s['http'])))->toBeEmpty();
    });
});

describe('what reaches the graph', function () {
    it('puts the calls a route reaches on its nodes, deduplicated', function () {
        $routes = (new RouteAnalyzer)->analyze(fixture('outgoing-http-project'));
        $controllers = (new ControllerAnalyzer)->analyze(fixture('outgoing-http-project'), $routes);
        $traces = (new MethodTracer)->trace($controllers);

        $graph = (new GraphBuilder)->build(
            'test',
            $routes,
            new MiddlewareRegistry([], [], []),
            $controllers,
            $traces,
            [],
            fixture('outgoing-http-project'),
        );

        $calls = [];
        foreach ($graph->nodes() as $node) {
            foreach ($node->data['httpCalls'] ?? [] as $call) {
                $calls[$node->id][] = $call;
            }
        }

        // Three rows for four calls in the method: the two identical pings are one third party
        // with one answer to every question worth asking, and the request inside the loop is only
        // reachable by walking the whole step tree.
        $action = collect($calls)->first(fn ($rows, $id) => str_contains((string) $id, 'PaymentController::store'));
        expect($action)->toHaveCount(3)
            ->and(array_column($action, 'url'))->toBe([
                'https://api.example.test/ping',
                'https://api.example.test/orders/…',
                'https://api.stripe.test/v1/charges',
            ]);

        $charge = $action[2];
        expect($charge['client'])->toBe('laravel')
            ->and($charge['method'])->toBe('POST')
            ->and($charge['host'])->toBe('api.stripe.test')
            ->and($charge['retryTimes'])->toBe(3);

        // The Guzzle call lives in a service the controller reaches, so it is the service node
        // that reports it — the same place its flow chart is.
        $service = collect($calls)->first(fn ($rows, $id) => str_contains(strtolower((string) $id), 'ledgerclient'));
        expect($service)->toHaveCount(1)
            ->and($service[0]['client'])->toBe('guzzle')
            ->and($service[0]['url'])->toBe('https://ledger.test/v2/balance');
    });

    it('leaves the key off nodes that call nobody', function () {
        $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
        $controllers = (new ControllerAnalyzer)->analyze(fixture('laravel-project'), $routes);
        $traces = (new MethodTracer)->trace($controllers);

        $graph = (new GraphBuilder)->build(
            'test',
            $routes,
            new MiddlewareRegistry([], [], []),
            $controllers,
            $traces,
            [],
            fixture('laravel-project'),
        );

        $withCalls = array_filter($graph->nodes(), fn ($n) => isset($n->data['httpCalls']));

        expect($withCalls)->toBe([]);
    });
});
