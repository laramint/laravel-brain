<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * What one service provider class declares about its own loading.
 *
 * Laravel decides a provider is deferred in exactly one place —
 * `ServiceProvider::isDeferred()`, which is `$this instanceof DeferrableProvider` and nothing
 * else (laravel/framework v13.29.0, Illuminate/Support/ServiceProvider.php:566). Everything the
 * framework then does with a deferred provider is driven by the strings its `provides()` returns:
 * `ProviderRepository::compileManifest()` writes one manifest entry per returned string mapping
 * it to the provider, and `Application::loadDeferredProviderIfNeeded()` registers the provider
 * the first time `make()`/`resolve()` is handed a key that appears in that map.
 *
 * So `provides()` is not documentation. It is the entire trigger set, and both fields below that
 * carry a defect follow from that fact.
 */
final class ServiceProviderRecord
{
    /**
     * @param  list<string>  $provides  service keys returned by provides(), FQCNs and container
     *                                  aliases alike — Laravel treats both as plain manifest keys
     * @param  bool  $providesIsDynamic  provides() was found but could not be read statically
     *                                   (built from a variable, a function call, a merge). Every
     *                                   defect check below stays silent when this is true.
     * @param  list<string>  $when  event classes from when(), which register the provider on
     *                              dispatch instead of on resolution
     * @param  list<string>  $bindingKeys  first arguments of the container registrations this
     *                                     provider makes — what it could legitimately provide
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public bool $deferred,
        public bool $legacyDeferProperty,
        public array $provides,
        public bool $providesIsDynamic,
        public array $when,
        public array $bindingKeys,
    ) {}

    /**
     * A deferred provider that provides nothing is never registered by anything.
     *
     * `compileManifest()` iterates `$instance->provides()` to write the manifest; an empty list
     * writes no entries, so no `make()` can ever match it, and it is not in the eager list either
     * because `isDeferred()` said true. Unless it also declares `when()` events, its `register()`
     * and `boot()` never run — silently, with no error anywhere.
     *
     * The commonest shape is a provider that implements DeferrableProvider without overriding
     * `provides()` at all: the base `ServiceProvider::provides()` returns `[]`.
     */
    public function neverBoots(): bool
    {
        return $this->deferred
            && ! $this->providesIsDynamic
            && $this->provides === []
            && $this->when === [];
    }

    /**
     * Service keys promised by provides() that this provider is not seen to register.
     *
     * Resolving such a key boots the provider — and then still fails, because nothing bound it.
     * The classic version is a provider that binds the contract but lists the implementation.
     *
     * Silent unless at least one binding was recognised: with no recognised bindings we have
     * learned nothing about what the provider registers (it may bind in a helper, a loop, or a
     * trait), and reporting every entry would be noise rather than a finding.
     *
     * @return list<string>
     */
    public function unbackedProvides(): array
    {
        if (! $this->deferred || $this->providesIsDynamic || $this->bindingKeys === []) {
            return [];
        }

        return array_values(array_diff($this->provides, $this->bindingKeys));
    }

    /**
     * `protected $defer = true;` on a provider that does not implement DeferrableProvider.
     *
     * That property was the pre-5.8 way to defer and no code reads it any more — grepping
     * laravel/framework v13.29.0 for `$this->defer` returns nothing. The provider is eagerly
     * registered on every single request while its author believes it is deferred, which is the
     * opposite defect to {@see neverBoots()} and just as quiet.
     */
    public function legacyDeferIgnored(): bool
    {
        return $this->legacyDeferProperty && ! $this->deferred;
    }
}
