<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * One event class, and what the application does with it.
 *
 * `deferred` is the one property that changes what an event *means* rather than describing it.
 * An event implementing `ShouldDispatchAfterCommit` is held until the outermost transaction
 * commits; without it, listeners run while the write is still provisional, and a rollback leaves
 * them having acted on something that never happened.
 */
final class EventDefinition
{
    /**
     * @param  list<string>  $properties  Public properties, which is what a consumer can branch on.
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $file = '',
        public readonly bool $deferred = false,
        public readonly bool $broadcast = false,
        public readonly array $properties = [],
    ) {}

    public function shortName(): string
    {
        $position = strrpos($this->fqcn, '\\');

        return $position === false ? $this->fqcn : substr($this->fqcn, $position + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'file' => $this->file,
            'deferred' => $this->deferred,
            'broadcast' => $this->broadcast,
            'properties' => $this->properties,
        ];
    }
}
