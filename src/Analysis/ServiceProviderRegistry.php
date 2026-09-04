<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Maps service provider FQCNs to what they declare about their own loading.
 */
final class ServiceProviderRegistry
{
    /** @var array<string, ServiceProviderRecord> */
    private array $providers = [];

    public function add(ServiceProviderRecord $record): void
    {
        if ($record->fqcn === '') {
            return;
        }
        $this->providers[$record->fqcn] = $record;
    }

    public function get(string $fqcn): ?ServiceProviderRecord
    {
        return $this->providers[$fqcn] ?? null;
    }

    /**
     * @return array<string, ServiceProviderRecord>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, ServiceProviderRecord>
     */
    public function deferred(): array
    {
        return array_filter($this->providers, static fn (ServiceProviderRecord $r): bool => $r->deferred);
    }

    /**
     * The statically knowable half of Laravel's deferred-services manifest: service key →
     * provider that would be registered the first time the container is asked for that key.
     *
     * Same shape and same last-one-wins collision handling as
     * `ProviderRepository::compileManifest()`, which writes `$manifest['deferred'][$service]`
     * in provider order.
     *
     * @return array<string, string>
     */
    public function deferredServices(): array
    {
        $manifest = [];

        foreach ($this->providers as $record) {
            if (! $record->deferred) {
                continue;
            }
            foreach ($record->provides as $service) {
                $manifest[$service] = $record->fqcn;
            }
        }

        return $manifest;
    }
}
