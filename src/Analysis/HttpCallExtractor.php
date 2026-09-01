<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * One outgoing HTTP request, as far as it can be read off the source.
 *
 * Every field is allowed to say "I don't know", and saying so is the point: a request whose URL is
 * computed at runtime is still a request to a third party, and hiding it because its address is
 * unreadable would lose the fact that matters most. So `url` may be empty, `method` may be empty,
 * and `urlSource` records *how much* the source actually revealed:
 *
 *   literal      the whole URL is a string in the code
 *   constructed  it starts with a literal and continues with something computed
 *                (`'https://api.stripe.com/v1/charges/'.$id`) — `url` keeps the readable prefix
 *                and ends in an ellipsis
 *   config       it comes from `config('services.allegro.url')` — `configKey` names the key,
 *                which identifies the integration as well as the URL would, and `url` keeps any
 *                literal path appended to it
 *   env          same, from `env(...)`
 *   dynamic      a variable, property or constant: nothing is claimed
 *
 * `timeout`, `retryTimes` and `retrySleep` are null when the code does not declare them. That is
 * not missing data — an outgoing call with no timeout is a finding, and the reader is entitled to
 * see the difference between "no timeout" and "a timeout we could not read".
 */
class HttpCall
{
    public function __construct(
        public string $client,      // 'laravel' | 'guzzle' | 'curl' | 'stream'
        public string $method,      // 'GET' | 'POST' | … | '' when the source does not name one
        public string $url,
        public string $host,
        public string $urlSource,   // 'literal' | 'constructed' | 'config' | 'env' | 'dynamic'
        public string $configKey,
        public ?float $timeout,
        public ?int $retryTimes,
        public ?int $retrySleep,    // milliseconds, as Laravel's retry() takes them
        public bool $async,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client' => $this->client,
            'method' => $this->method,
            'url' => $this->url,
            'host' => $this->host,
            'urlSource' => $this->urlSource,
            'configKey' => $this->configKey,
            'timeout' => $this->timeout,
            'retryTimes' => $this->retryTimes,
            'retrySleep' => $this->retrySleep,
            'async' => $this->async,
        ];
    }
}

/**
 * Recognises outgoing HTTP requests in an expression.
 *
 * This is the same kind of shape-classifier {@see FlowExtractor} already applies to calls — a
 * `Job::dispatch()` becomes a `dispatch` step, an `event()` becomes an `event` step — extended to
 * the one call shape that leaves the application entirely. It is driven from FlowExtractor rather
 * than run as a pass of its own so that a single walk over a method produces both the flow chart
 * and the list of third parties that chart talks to, and so that the calls hang off the exact step
 * that makes them.
 *
 * Four families are recognised:
 *
 *   laravel  the `Http` facade, including everything the builder puts between the facade and the
 *            verb: `Http::withToken($t)->retry(3, 100)->timeout(5)->post($url)`. A pending request
 *            parked in a variable (`$req = Http::baseUrl(...); $req->get(...)`) is followed too.
 *   guzzle   `new Client(...)` and its verbs, including the `…Async` ones. The client has to be
 *            constructed where we can see it — inline, or assigned to a variable or `$this->…`
 *            property earlier in the same method. A client injected through the constructor is
 *            invisible here, because a method AST does not carry the class's other methods.
 *   curl     `curl_exec()`, carrying whatever `curl_init()` / `curl_setopt()` said about the same
 *            handle earlier in the method.
 *   stream   `file_get_contents()` on an argument that visibly starts with `http://`/`https://`.
 *            Anything less than visible is left alone: a computed argument to file_get_contents is
 *            far more often a path on disk than a URL, and a wrong "this calls a third party" is
 *            worse than a missing one.
 *
 * State (which variable holds which client) is per method: {@see reset()} clears it, and callers
 * feed statements in source order, so an assignment is seen before the call that uses it.
 */
class HttpCallExtractor
{
    /**
     * Verbs on Laravel's PendingRequest. `send($method, $url)` names its own method in argument
     * one, so it maps to nothing here and is resolved when the call is built.
     */
    private const LARAVEL_VERBS = [
        'get' => 'GET',
        'post' => 'POST',
        'put' => 'PUT',
        'patch' => 'PATCH',
        'delete' => 'DELETE',
        'head' => 'HEAD',
        'send' => '',
    ];

    /**
     * Verbs on a Guzzle client. `request()` and `send()` name their own method (or carry a PSR-7
     * request that does), so they map to nothing and are resolved when the call is built.
     */
    private const GUZZLE_VERBS = [
        'get' => 'GET',
        'post' => 'POST',
        'put' => 'PUT',
        'patch' => 'PATCH',
        'delete' => 'DELETE',
        'head' => 'HEAD',
        'options' => 'OPTIONS',
        'request' => '',
        'send' => '',
    ];

    private const HTTP_FACADE = 'Illuminate\\Support\\Facades\\Http';

    private const GUZZLE_CLIENT = 'GuzzleHttp\\Client';

    /**
     * Expressions scanned for outgoing calls in this process.
     *
     * @internal Counter for tests and benchmarks; never read by production code. It exists so a
     * test can tell "the feature is off" from "the feature ran and found nothing" — the two look
     * identical from the outside, and only one of them is what the config switch promises.
     */
    public static int $scanCount = 0;

    /** @var array<string, string> alias => FQCN, for ASTs the NameResolver never saw */
    private array $useMap = [];

    /** @var array<string, array<string, mixed>> variable label => settings of a parked pending request */
    private array $laravelClients = [];

    /** @var array<string, array<string, mixed>> variable label => settings of a constructed Guzzle client */
    private array $guzzleClients = [];

    /** @var array<string, array<string, mixed>> variable label => what a curl handle has been told */
    private array $curlHandles = [];

    /**
     * Forget every client seen so far. Called at the start of each method: variables do not
     * survive across methods, and letting `$client` mean whatever it meant in the previous method
     * would invent calls that the code does not make.
     *
     * @param  array<string, string>  $useMap
     */
    public function reset(array $useMap): void
    {
        $this->useMap = $useMap;
        $this->laravelClients = [];
        $this->guzzleClients = [];
        $this->curlHandles = [];
    }

    /**
     * Every outgoing request an expression makes, in source order.
     *
     * `$descendIntoClosures` says whether the caller has already charted the closures inside this
     * expression as steps of their own. When it has, descending here as well would report every
     * request made inside a `DB::transaction(...)` or a `retry(...)` twice — once from the wrapper
     * and once from the body. When it has not (an assignment or a return holding the same call:
     * `$x = retry(3, fn () => Http::get(...))`), nothing else will ever look inside, so this is
     * the only chance to see the request at all.
     *
     * @return HttpCall[]
     */
    public function fromExpression(Node\Expr $expr, bool $descendIntoClosures = false): array
    {
        self::$scanCount++;

        $visitor = new HttpCallVisitor($this, $descendIntoClosures);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse([$expr]);

        return $visitor->calls;
    }

    /**
     * Note an assignment that puts a client somewhere we can recognise it later.
     *
     * @internal driven by {@see HttpCallVisitor}
     */
    public function remember(Node\Expr\Assign $assign): void
    {
        $label = $this->variableLabel($assign->var);
        if ($label === null) {
            return;
        }

        $value = $assign->expr;

        if ($value instanceof Node\Expr\New_ && $this->isGuzzleClient($value)) {
            $this->guzzleClients[$label] = $this->guzzleConstructorOptions($value);

            return;
        }

        if ($value instanceof Node\Expr\FuncCall
            && $value->name instanceof Node\Name
            && $value->name->toString() === 'curl_init'
        ) {
            $this->curlHandles[$label] = ['url' => $this->describeUrl($this->argValue($value->args, 0))];

            return;
        }

        // `$request = Http::withToken($token)->baseUrl($base);` — a pending request parked for
        // later. Only the settings are kept: the chain has no verb, so it makes no call yet.
        $chain = $this->laravelChain($value);
        if ($chain !== null) {
            $this->laravelClients[$label] = $chain;
        }
    }

    /**
     * The outgoing request an expression is, or null when it is not one.
     *
     * @internal driven by {@see HttpCallVisitor}
     */
    public function classify(Node\Expr $expr): ?HttpCall
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            return $this->classifyStaticCall($expr);
        }
        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->classifyMethodCall($expr);
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            return $this->classifyFuncCall($expr);
        }

        return null;
    }

    // ── Laravel's client ──────────────────────────────────────────────────────

    private function classifyStaticCall(Node\Expr\StaticCall $call): ?HttpCall
    {
        if (! $this->isHttpFacade($call->class)) {
            return null;
        }

        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

        // `Http::pool(fn ($pool) => [...])` fires several requests whose verbs live inside the
        // closure, on a `$pool` object that nothing else in the method touches. It is reported as
        // the one concurrent call it syntactically is; reporting the inner verbs as well would
        // count the same requests twice, and reporting only them would go silent whenever the
        // closure builds its list from a variable.
        if ($method === 'pool') {
            return new HttpCall('laravel', '', '', '', 'dynamic', '', null, null, null, true);
        }

        if (! array_key_exists($method, self::LARAVEL_VERBS)) {
            return null;
        }

        return $this->laravelCall($method, $call->args, []);
    }

    private function classifyMethodCall(Node\Expr\MethodCall $call): ?HttpCall
    {
        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
        if ($method === '') {
            return null;
        }

        $settings = $this->laravelChain($call->var);
        if ($settings !== null && array_key_exists($method, self::LARAVEL_VERBS)) {
            return $this->laravelCall($method, $call->args, $settings);
        }

        $guzzle = $this->guzzleReceiver($call->var);
        if ($guzzle !== null && array_key_exists($this->stripAsync($method), self::GUZZLE_VERBS)) {
            return $this->guzzleCall($method, $call->args, $guzzle);
        }

        return null;
    }

    /**
     * Walk a receiver back to Laravel's `Http` facade, collecting what the builder declared on the
     * way. Returns null when the chain starts anywhere else — which is how `$repo->get()` and
     * `Http::fake()` stay out of the report.
     *
     * @return array<string, mixed>|null
     */
    private function laravelChain(Node\Expr $expr): ?array
    {
        $settings = [];
        $node = $expr;

        while ($node instanceof Node\Expr\MethodCall) {
            $name = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            $this->collectPendingRequestSetting($name, $node->args, $settings);
            $node = $node->var;
        }

        if ($node instanceof Node\Expr\StaticCall && $this->isHttpFacade($node->class)) {
            $name = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            $this->collectPendingRequestSetting($name, $node->args, $settings);

            return $settings;
        }

        $label = $this->variableLabel($node);
        if ($label !== null && isset($this->laravelClients[$label])) {
            // Settings on the parked request are the defaults; anything re-declared on this chain
            // is nearer the call and wins.
            return $settings + $this->laravelClients[$label];
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  array<string, mixed>  $settings
     */
    private function collectPendingRequestSetting(string $method, array $args, array &$settings): void
    {
        switch ($method) {
            case 'baseUrl':
                $settings['base'] ??= $this->describeUrl($this->argValue($args, 0));
                break;
            case 'timeout':
            case 'connectTimeout':
                $settings['timeout'] ??= $this->numberValue($this->argValue($args, 0));
                break;
            case 'retry':
                $settings['retryTimes'] ??= $this->intValue($this->argValue($args, 0));
                $settings['retrySleep'] ??= $this->intValue($this->argValue($args, 1));
                break;
            case 'withOptions':
                $options = $this->arrayOptions($this->argValue($args, 0));
                if (isset($options['timeout'])) {
                    $settings['timeout'] ??= $this->numberValue($options['timeout']);
                }
                if (isset($options['base_uri'])) {
                    $settings['base'] ??= $this->describeUrl($options['base_uri']);
                }
                break;
        }
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  array<string, mixed>  $settings
     */
    private function laravelCall(string $verb, array $args, array $settings): HttpCall
    {
        $method = self::LARAVEL_VERBS[$verb];
        $urlArgIndex = 0;

        if ($verb === 'send') {
            // send('POST', $url) — the verb is the first argument.
            $method = $this->stringValue($this->argValue($args, 0)) ?? '';
            $urlArgIndex = 1;
        }

        $url = $this->resolveUrl($this->describeUrl($this->argValue($args, $urlArgIndex)), $settings);

        return new HttpCall(
            'laravel',
            strtoupper($method),
            $url['url'],
            $this->hostOf($url['url']),
            $url['source'],
            $url['configKey'],
            $this->settingFloat($settings, 'timeout'),
            $this->settingInt($settings, 'retryTimes'),
            $this->settingInt($settings, 'retrySleep'),
            false,
        );
    }

    // ── Guzzle ────────────────────────────────────────────────────────────────

    /**
     * The settings of the Guzzle client a receiver refers to, or null when the receiver is not one
     * we watched being constructed.
     *
     * @return array<string, mixed>|null
     */
    private function guzzleReceiver(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\New_ && $this->isGuzzleClient($expr)) {
            return $this->guzzleConstructorOptions($expr);
        }

        $label = $this->variableLabel($expr);

        return $label !== null ? ($this->guzzleClients[$label] ?? null) : null;
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     * @param  array<string, mixed>  $settings
     */
    private function guzzleCall(string $rawVerb, array $args, array $settings): HttpCall
    {
        $async = str_ends_with($rawVerb, 'Async');
        $verb = $this->stripAsync($rawVerb);
        $method = self::GUZZLE_VERBS[$verb];
        $urlArgIndex = 0;
        $optionsArgIndex = 1;

        if ($verb === 'request') {
            // request('POST', $url, [...])
            $method = $this->stringValue($this->argValue($args, 0)) ?? '';
            $urlArgIndex = 1;
            $optionsArgIndex = 2;
        }
        if ($verb === 'send') {
            // send($psr7Request) — the method and URI live inside an object we cannot read.
            $method = '';
            $urlArgIndex = -1;
            $optionsArgIndex = 1;
        }

        $url = $this->resolveUrl(
            $urlArgIndex < 0
                ? ['url' => '', 'source' => 'dynamic', 'configKey' => '']
                : $this->describeUrl($this->argValue($args, $urlArgIndex)),
            $settings,
        );

        $timeout = $this->settingFloat($settings, 'timeout');
        $options = $this->arrayOptions($this->argValue($args, $optionsArgIndex));
        if (isset($options['timeout'])) {
            $timeout = $this->numberValue($options['timeout']) ?? $timeout;
        }

        return new HttpCall(
            'guzzle',
            strtoupper($method),
            $url['url'],
            $this->hostOf($url['url']),
            $url['source'],
            $url['configKey'],
            $timeout,
            null,
            null,
            $async,
        );
    }

    /**
     * `base_uri` and `timeout` out of `new Client([...])`.
     *
     * @return array<string, mixed>
     */
    private function guzzleConstructorOptions(Node\Expr\New_ $new): array
    {
        $settings = [];
        $options = $this->arrayOptions($this->argValue($new->args, 0));

        if (isset($options['base_uri'])) {
            $settings['base'] = $this->describeUrl($options['base_uri']);
        }
        if (isset($options['timeout'])) {
            $settings['timeout'] = $this->numberValue($options['timeout']);
        }

        return $settings;
    }

    private function isGuzzleClient(Node\Expr\New_ $new): bool
    {
        return $new->class instanceof Node\Name && $this->resolvesTo($new->class, self::GUZZLE_CLIENT, 'Client');
    }

    private function stripAsync(string $method): string
    {
        return str_ends_with($method, 'Async') ? substr($method, 0, -5) : $method;
    }

    // ── curl and the stream wrapper ───────────────────────────────────────────

    private function classifyFuncCall(Node\Expr\FuncCall $call): ?HttpCall
    {
        if (! $call->name instanceof Node\Name) {
            return null;
        }

        switch ($call->name->toString()) {
            case 'curl_setopt':
                $this->rememberCurlOption($call);

                return null;

            case 'curl_exec':
                return $this->curlCall($call);

            case 'file_get_contents':
                return $this->streamCall($call);

            default:
                return null;
        }
    }

    private function rememberCurlOption(Node\Expr\FuncCall $call): void
    {
        $handle = $this->argValue($call->args, 0);
        $option = $this->argValue($call->args, 1);
        $value = $this->argValue($call->args, 2);
        if ($handle === null || ! $option instanceof Node\Expr\ConstFetch || $value === null) {
            return;
        }

        $label = $this->variableLabel($handle);
        if ($label === null) {
            return;
        }

        switch ($option->name->toString()) {
            case 'CURLOPT_URL':
                $this->curlHandles[$label]['url'] = $this->describeUrl($value);
                break;
            case 'CURLOPT_CUSTOMREQUEST':
                $this->curlHandles[$label]['method'] = $this->stringValue($value) ?? '';
                break;
            case 'CURLOPT_POST':
                $this->curlHandles[$label]['method'] ??= 'POST';
                break;
            case 'CURLOPT_TIMEOUT':
            case 'CURLOPT_CONNECTTIMEOUT':
                $this->curlHandles[$label]['timeout'] ??= $this->numberValue($value);
                break;
        }
    }

    private function curlCall(Node\Expr\FuncCall $call): HttpCall
    {
        $handle = $this->argValue($call->args, 0);
        $label = $handle !== null ? $this->variableLabel($handle) : null;
        $facts = $label !== null ? ($this->curlHandles[$label] ?? []) : [];

        $url = is_array($facts['url'] ?? null)
            ? $facts['url']
            : ['url' => '', 'source' => 'dynamic', 'configKey' => ''];

        return new HttpCall(
            'curl',
            strtoupper(is_string($facts['method'] ?? null) ? $facts['method'] : ''),
            is_string($url['url']) ? $url['url'] : '',
            $this->hostOf(is_string($url['url']) ? $url['url'] : ''),
            is_string($url['source']) ? $url['source'] : 'dynamic',
            is_string($url['configKey']) ? $url['configKey'] : '',
            $this->settingFloat($facts, 'timeout'),
            null,
            null,
            false,
        );
    }

    private function streamCall(Node\Expr\FuncCall $call): ?HttpCall
    {
        $described = $this->describeUrl($this->argValue($call->args, 0));
        $url = is_string($described['url']) ? $described['url'] : '';

        // Only a visible scheme proves this is a network call rather than a file read.
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return new HttpCall(
            'stream',
            'GET',
            $url,
            $this->hostOf($url),
            is_string($described['source']) ? $described['source'] : 'dynamic',
            '',
            null,
            null,
            null,
            false,
        );
    }

    // ── Reading values out of the tree ────────────────────────────────────────

    /**
     * What an expression says about a URL. See {@see HttpCall} for what each source means.
     *
     * @return array{url: string, source: string, configKey: string}
     */
    private function describeUrl(?Node\Expr $expr): array
    {
        if ($expr === null) {
            return ['url' => '', 'source' => 'dynamic', 'configKey' => ''];
        }

        if ($expr instanceof Node\Scalar\String_) {
            return ['url' => $expr->value, 'source' => 'literal', 'configKey' => ''];
        }

        $configKey = $this->configCallKey($expr);
        if ($configKey !== null) {
            return ['url' => '', 'source' => $configKey['source'], 'configKey' => $configKey['key']];
        }

        $parts = $this->flattenStringParts($expr);
        if ($parts === null) {
            return ['url' => '', 'source' => 'dynamic', 'configKey' => ''];
        }

        // A URL is read left to right, so the leading literal run is the part worth keeping: it
        // carries the scheme and host, which is the whole question "which third party?".
        $prefix = '';
        $source = 'literal';
        $key = '';
        $index = 0;
        foreach ($parts as $part) {
            $literal = $this->stringValue($part);
            if ($literal !== null) {
                $prefix .= $literal;
                $index++;

                continue;
            }

            $config = $index === 0 ? $this->configCallKey($part) : null;
            if ($config !== null) {
                $source = $config['source'];
                $key = $config['key'];
                $index++;

                continue;
            }

            $source = $source === 'literal' ? 'constructed' : $source;
            break;
        }

        if ($prefix === '' && $key === '') {
            return ['url' => '', 'source' => 'dynamic', 'configKey' => ''];
        }

        return [
            'url' => $source === 'constructed' ? $prefix.'…' : $prefix,
            'source' => $source,
            'configKey' => $key,
        ];
    }

    /**
     * The pieces of a concatenation or an interpolated string, left to right. Null for anything
     * that is not one — a bare variable has no pieces to read.
     *
     * @return Node\Expr[]|null
     */
    private function flattenStringParts(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->flattenStringParts($expr->left) ?? [$expr->left];
            $right = $this->flattenStringParts($expr->right) ?? [$expr->right];

            return array_merge($left, $right);
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof Node\Scalar\EncapsedStringPart
                    ? new Node\Scalar\String_($part->value)
                    : $part;
            }

            return $parts;
        }

        return null;
    }

    /**
     * `config('services.x.url')` / `env('X_URL')` — the key names the integration as well as the
     * URL would, so it is reported instead of a guess at what the key resolves to.
     *
     * @return array{source: string, key: string}|null
     */
    private function configCallKey(Node\Expr $expr): ?array
    {
        if (! $expr instanceof Node\Expr\FuncCall || ! $expr->name instanceof Node\Name) {
            return null;
        }

        $name = $expr->name->toString();
        if ($name !== 'config' && $name !== 'env') {
            return null;
        }

        $key = $this->stringValue($this->argValue($expr->args, 0));

        return $key === null ? null : ['source' => $name === 'config' ? 'config' : 'env', 'key' => $key];
    }

    /**
     * Fold a base URL declared on the builder into the URL passed to the verb.
     *
     * @param  array{url: string, source: string, configKey: string}  $url
     * @param  array<string, mixed>  $settings
     * @return array{url: string, source: string, configKey: string}
     */
    private function resolveUrl(array $url, array $settings): array
    {
        $base = $settings['base'] ?? null;
        if (! is_array($base)) {
            return $url;
        }

        $baseUrl = is_string($base['url'] ?? null) ? $base['url'] : '';
        $baseSource = is_string($base['source'] ?? null) ? $base['source'] : 'dynamic';
        $baseKey = is_string($base['configKey'] ?? null) ? $base['configKey'] : '';

        // An absolute URL at the call site ignores the base — that is what the client does too.
        if (preg_match('#^https?://#i', $url['url'])) {
            return $url;
        }

        if ($baseSource === 'config' || $baseSource === 'env') {
            return ['url' => $url['url'], 'source' => $baseSource, 'configKey' => $baseKey];
        }

        if ($baseUrl === '') {
            return $url;
        }

        $joined = rtrim($baseUrl, '/').'/'.ltrim($url['url'], '/');
        $source = ($baseSource === 'literal' && $url['source'] === 'literal') ? 'literal' : 'constructed';

        return ['url' => $joined, 'source' => $source, 'configKey' => $url['configKey']];
    }

    private function hostOf(string $url): string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }

        $host = parse_url(rtrim($url, '…'), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * The array literal an options argument is, keyed by its literal string keys.
     *
     * @return array<string, Node\Expr>
     */
    private function arrayOptions(?Node\Expr $expr): array
    {
        if (! $expr instanceof Node\Expr\Array_) {
            return [];
        }

        $options = [];
        foreach ($expr->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }
            $key = $this->stringValue($item->key);
            if ($key !== null) {
                $options[$key] = $item->value;
            }
        }

        return $options;
    }

    /**
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function argValue(array $args, int $index): ?Node\Expr
    {
        $arg = $args[$index] ?? null;

        return $arg instanceof Node\Arg ? $arg->value : null;
    }

    private function stringValue(?Node\Expr $expr): ?string
    {
        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    private function numberValue(?Node\Expr $expr): ?float
    {
        if ($expr instanceof Node\Scalar\LNumber) {
            return (float) $expr->value;
        }
        if ($expr instanceof Node\Scalar\DNumber) {
            return $expr->value;
        }

        return null;
    }

    private function intValue(?Node\Expr $expr): ?int
    {
        return $expr instanceof Node\Scalar\LNumber ? $expr->value : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function settingFloat(array $settings, string $key): ?float
    {
        $value = $settings[$key] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function settingInt(array $settings, string $key): ?int
    {
        $value = $settings[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * A stable name for the thing a client was assigned to. Only `$var` and `$this->prop` get one:
     * an element of an array or a property of a property is not something we can follow with any
     * confidence, and a wrong match here would attach one integration's settings to another's call.
     */
    private function variableLabel(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return '$'.$expr->name;
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
        ) {
            return '$this->'.$expr->name->toString();
        }

        return null;
    }

    private function isHttpFacade(Node\Name|Node\Expr $class): bool
    {
        return $class instanceof Node\Name && $this->resolvesTo($class, self::HTTP_FACADE, 'Http');
    }

    /**
     * Whether a name is the class we are looking for, by the resolver first and the file's imports
     * second, with the bare short name accepted last — a Laravel app may alias the facade at the
     * root namespace, where there is no import to read.
     */
    private function resolvesTo(Node\Name $name, string $fqcn, string $shortName): bool
    {
        $resolved = PhpFileParser::resolvedName($name);
        if ($resolved !== null) {
            return $resolved === $fqcn || $resolved === $shortName;
        }

        $written = $name->toString();
        $mapped = $this->useMap[$written] ?? $written;

        return $mapped === $fqcn || $mapped === $shortName;
    }
}

/**
 * Walks one expression, asking the extractor about every call it meets.
 *
 * Entering a node before its children is what keeps a builder chain from being counted twice: the
 * terminal `->get()` is classified first, and the `Http::withToken()` underneath it is not a verb,
 * so it classifies as nothing.
 */
class HttpCallVisitor extends NodeVisitorAbstract
{
    /** @var HttpCall[] */
    public array $calls = [];

    public function __construct(
        private HttpCallExtractor $extractor,
        private bool $descendIntoClosures = false,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if (! $this->descendIntoClosures
            && ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
        ) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->extractor->remember($node);
        }

        if ($node instanceof Node\Expr) {
            $call = $this->extractor->classify($node);
            if ($call !== null) {
                $this->calls[] = $call;
            }
        }

        return null;
    }
}
