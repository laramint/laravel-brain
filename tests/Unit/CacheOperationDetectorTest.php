<?php

use LaraMint\LaravelBrain\Analysis\CacheOperation;
use LaraMint\LaravelBrain\Analysis\CacheOperationDetector;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * The first cache operation the detector finds anywhere in a statement, as an array.
 *
 * Walking rather than handing one expression straight over is what the callers do: FlowExtractor
 * offers whatever drives the statement, which for `$x = Cache::get(...)` is the assignment and
 * only then the call.
 */
function detectedCacheOp(string $statement, string $uses = ''): ?array
{
    $parsed = (new PhpFileParser)->parseCode(<<<PHP
        <?php

        namespace App;

        {$uses}

        class Subject
        {
            public function handle(\$id, \$key, \$name, \$value)
            {
        {$statement}
            }
        }
        PHP);

    $detector = new CacheOperationDetector;
    $useMap = $parsed['useMap'] ?? [];
    $found = null;

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new class($detector, $useMap, $found) extends NodeVisitorAbstract
    {
        public function __construct(
            private CacheOperationDetector $detector,
            private array $useMap,
            private mixed &$found,
        ) {}

        public function enterNode(Node $node): ?int
        {
            if ($this->found === null && $node instanceof Node\Expr) {
                $operation = $this->detector->detect($node, $this->useMap);
                if ($operation !== null) {
                    $this->found = $operation->toArray();
                }
            }

            return null;
        }
    });
    $traverser->traverse($parsed['ast'] ?? []);

    return $found;
}

it('classifies each cache method by what it does to cache state', function (string $call, string $kind, string $method) {
    expect(detectedCacheOp("        {$call};"))
        ->toMatchArray(['kind' => $kind, 'method' => $method]);
})->with([
    'get is a read' => ['\Cache::get("users")', 'read', 'get'],
    'has is a read' => ['\Cache::has("users")', 'read', 'has'],
    // remember writes only on a miss; what a reader needs from it is "this can be stale".
    'remember is a read' => ['\Cache::remember("users", 60, fn () => 1)', 'read', 'remember'],
    'rememberForever is a read' => ['\Cache::rememberForever("users", fn () => 1)', 'read', 'rememberForever'],
    'put is a write' => ['\Cache::put("users", $value, 60)', 'write', 'put'],
    'add is a write' => ['\Cache::add("users", $value, 60)', 'write', 'add'],
    'forever is a write' => ['\Cache::forever("users", $value)', 'write', 'forever'],
    'increment is a write' => ['\Cache::increment("hits")', 'write', 'increment'],
    'decrement is a write' => ['\Cache::decrement("hits")', 'write', 'decrement'],
    'forget invalidates' => ['\Cache::forget("users")', 'invalidate', 'forget'],
    'flush invalidates' => ['\Cache::flush()', 'invalidate', 'flush'],
    // The one read that also clears the key, so someone tracing "what forgets this" must find it.
    'pull invalidates' => ['\Cache::pull("users")', 'invalidate', 'pull'],
    'lock is a lock' => ['\Cache::lock("deploy", 30)', 'lock', 'lock'],
]);

it('reads a literal key', function () {
    expect(detectedCacheOp('        \Cache::get("users.index");'))
        ->toMatchArray(['key' => 'users.index', 'keyKind' => 'literal']);
});

it('renders a concatenated key with its variable parts in braces', function () {
    expect(detectedCacheOp('        \Cache::get("user:" . $id . ":profile");'))
        ->toMatchArray(['key' => 'user:{$id}:profile', 'keyKind' => 'constructed']);
});

it('renders an interpolated key the same way as a concatenated one', function () {
    // The two spellings mean the same thing, so they must not read differently in the panel.
    expect(detectedCacheOp('        \Cache::get("user:{$id}");'))
        ->toMatchArray(['key' => 'user:{$id}', 'keyKind' => 'constructed']);
});

it('names a key held in a class constant rather than guessing its value', function () {
    expect(detectedCacheOp('        \Cache::forget(CacheKeys::USERS);'))
        ->toMatchArray(['key' => 'CacheKeys::USERS', 'keyKind' => 'constructed']);
});

it('says the key is computed instead of printing the variable holding it', function () {
    // Printing `$key` would read like a key and be one only by accident. The panel says so.
    expect(detectedCacheOp('        \Cache::forget($key);'))
        ->toMatchArray(['key' => '', 'keyKind' => 'computed']);
});

it('reports no key at all for a call that clears the whole store', function () {
    expect(detectedCacheOp('        \Cache::flush();'))
        ->toMatchArray(['key' => '', 'keyKind' => 'none']);
});

it('records a literal TTL against the argument each method puts it in', function (string $call, ?int $ttl) {
    expect(detectedCacheOp("        {$call};"))->toMatchArray(['ttl' => $ttl]);
})->with([
    'put takes it third' => ['\Cache::put("k", $value, 300)', 300],
    'remember takes it second' => ['\Cache::remember("k", 3600, fn () => 1)', 3600],
    'lock takes it second' => ['\Cache::lock("k", 30)', 30],
    'forever has none' => ['\Cache::forever("k", $value)', null],
    // now()->addHour() is a TTL, but not one that can be read as a number of seconds here.
    'a computed one is not guessed' => ['\Cache::put("k", $value, now()->addHour())', null],
]);

it('records the store a chain names', function () {
    expect(detectedCacheOp('        \Cache::store("redis")->get("users");'))
        ->toMatchArray(['store' => 'redis', 'method' => 'get', 'kind' => 'read']);
});

it('records a store named through driver() too', function () {
    expect(detectedCacheOp('        \Cache::driver("array")->forget("users");'))
        ->toMatchArray(['store' => 'array', 'kind' => 'invalidate']);
});

it('leaves the store empty when it is not a literal', function () {
    expect(detectedCacheOp('        \Cache::store($name)->get("users");'))
        ->toMatchArray(['store' => '', 'key' => 'users']);
});

it('records declared tags', function () {
    expect(detectedCacheOp('        \Cache::tags(["dashboard", "reports"])->forget("summary");'))
        ->toMatchArray(['tags' => ['dashboard', 'reports'], 'kind' => 'invalidate']);
});

it('records a store and tags from the same chain', function () {
    expect(detectedCacheOp('        \Cache::store("redis")->tags(["dashboard"])->put("summary", $value, 60);'))
        ->toMatchArray(['store' => 'redis', 'tags' => ['dashboard'], 'ttl' => 60]);
});

it('shows a non-literal tag as the expression it is', function () {
    expect(detectedCacheOp('        \Cache::tags([$name])->flush();'))
        ->toMatchArray(['tags' => ['{$name}']]);
});

it('reads the cache() helper given one key', function () {
    expect(detectedCacheOp('        $theme = cache("theme");'))
        ->toMatchArray(['kind' => 'read', 'method' => 'cache', 'key' => 'theme', 'keyKind' => 'literal']);
});

it('treats the cache() helper given an array as a write, keyed by the array keys', function () {
    // Exactly how the helper itself decides: an array is a write, anything else is a key to read.
    expect(detectedCacheOp('        cache(["theme" => "dark", "locale" => "en"], 120);'))
        ->toMatchArray(['kind' => 'write', 'key' => 'theme, locale', 'ttl' => 120]);
});

it('follows an operation called on the bare cache() repository', function () {
    expect(detectedCacheOp('        cache()->remember("users", 60, fn () => 1);'))
        ->toMatchArray(['kind' => 'read', 'method' => 'remember', 'key' => 'users']);
});

it('follows a chain built on the bare cache() repository', function () {
    expect(detectedCacheOp('        cache()->tags(["dashboard"])->forget("summary");'))
        ->toMatchArray(['kind' => 'invalidate', 'tags' => ['dashboard']]);
});

it('resolves the facade through an import alias', function () {
    expect(detectedCacheOp(
        '        Store::forget("users");',
        'use Illuminate\Support\Facades\Cache as Store;',
    ))->toMatchArray(['kind' => 'invalidate', 'method' => 'forget', 'key' => 'users']);
});

it('ignores a get() on something that is not the cache', function (string $call) {
    expect(detectedCacheOp("        {$call};"))->toBeNull();
})->with([
    // The property may hold a cache. Nothing in the source says so, and a guessed row would
    // undo the point of the section, which is that everything on it is a fact.
    'an injected repository' => ['$this->cache->get("users")'],
    'an unrelated collaborator' => ['$this->repository->forget("users")'],
    // Shaped exactly like a tagged cache chain, and rooted somewhere else entirely — the chain
    // walk has to reach the root before it believes any of it.
    'a chain that only looks like one' => ['$this->repository->tags(["users"])->forget("users")'],
    'an unrelated facade' => ['\Redis::get("users")'],
    'an unrelated static call' => ['\App\Models\User::get()'],
    'a cache method we do not model' => ['\Cache::supportsTags()'],
    'the bare repository on its own' => ['cache()'],
    'a same-named helper with no cache meaning' => ['cache_control("no-store")'],
]);

it('does not choke on a first-class callable', function () {
    // `Cache::get(...)` makes a closure and touches nothing; php-parser refuses to hand over
    // arguments that are a placeholder, so asking for them would have thrown here.
    expect(detectedCacheOp('        $reader = \Cache::get(...);'))->toBeNull();
});

it('gives two calls that say the same thing the same signature', function () {
    $first = detectedCacheOp('        \Cache::forget("users");');
    $second = detectedCacheOp('        \Cache::forget("users");');
    $other = detectedCacheOp('        \Cache::forget("orders");');

    expect(CacheOperation::signatureOf($first))->toBe(CacheOperation::signatureOf($second))
        ->and(CacheOperation::signatureOf($first))->not->toBe(CacheOperation::signatureOf($other));
});
