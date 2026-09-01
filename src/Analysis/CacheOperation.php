<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * One cache call read out of source: what it does to the cache, and to which key.
 *
 * The `kind` is the part that earns the display. "This method uses the cache" is not worth a
 * panel row — a read that can serve a stale value, a write that creates one, and a forget that
 * clears it are three different facts, and the question people actually arrive with ("why am I
 * seeing old data", "what invalidates this key") is answered by the kind and the key together.
 */
class CacheOperation
{
    /**
     * @param  string  $kind  'read' | 'write' | 'invalidate' | 'lock'
     * @param  string  $method  the cache method as written — `remember`, `forget`, `pull`, …
     * @param  string  $key  the key, rendered; '' when it could not be read from source
     * @param  string  $keyKind  'literal' | 'constructed' | 'computed' | 'none'
     * @param  string  $store  a literal `store()`/`driver()` name, '' for the default store
     * @param  string[]  $tags  declared tags; a non-literal one renders as `{$expr}`
     * @param  int|null  $ttl  a literal TTL in seconds, null when absent or not a literal
     */
    public function __construct(
        public string $kind,
        public string $method,
        public string $key,
        public string $keyKind,
        public string $store = '',
        public array $tags = [],
        public ?int $ttl = null,
    ) {}

    /**
     * @return array{kind: string, method: string, key: string, keyKind: string, store: string, tags: string[], ttl: int|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'method' => $this->method,
            'key' => $this->key,
            'keyKind' => $this->keyKind,
            'store' => $this->store,
            'tags' => $this->tags,
            'ttl' => $this->ttl,
        ];
    }

    /**
     * Identity for de-duplication: two calls saying the same thing about the same key are one
     * panel row, however many times the method makes them.
     *
     * Takes the array rather than the object because the only place that de-duplicates is
     * downstream of {@see toArray()} — the operations reach the graph riding on flow steps, and
     * rebuilding an object there just to ask it for its own identity would be ceremony.
     *
     * @param  array<string, mixed>  $operation
     */
    public static function signatureOf(array $operation): string
    {
        $tags = $operation['tags'] ?? [];

        return implode('|', [
            (string) ($operation['kind'] ?? ''),
            (string) ($operation['method'] ?? ''),
            (string) ($operation['key'] ?? ''),
            (string) ($operation['keyKind'] ?? ''),
            (string) ($operation['store'] ?? ''),
            is_array($tags) ? implode(',', array_map('strval', $tags)) : '',
            isset($operation['ttl']) ? (string) $operation['ttl'] : '',
        ]);
    }
}
