<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * What a queued job promises about its own retrying, uniqueness and gating.
 *
 * These are the facts that decide what happens when the job goes wrong, and none of them were on
 * the graph: a job node carried its name, its file and its flow, so "this runs on a queue" was
 * the whole story. Whether a failure is retried four times or once, whether a second dispatch is
 * dropped on the floor, and whether the job refuses to overlap with itself are the differences
 * that matter at three in the morning.
 *
 * A figure is null when it is not declared. It is `dynamic` when the class decides it at runtime
 * — `backoff()` computing a delay cannot be reduced to a number by reading the source, and
 * printing a guess would be worse than printing nothing.
 */
final class JobDefinition
{
    /**
     * @param  list<string>  $middleware  Short names of the middleware the job declares.
     * @param  list<string>  $dynamic  Facts the class computes at runtime rather than declares.
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly bool $queued = false,
        public readonly ?int $tries = null,
        public readonly ?int $timeout = null,
        public readonly ?int $backoff = null,
        public readonly ?int $maxExceptions = null,
        public readonly bool $unique = false,
        public readonly bool $uniqueUntilProcessing = false,
        public readonly ?int $uniqueFor = null,
        public readonly bool $encrypted = false,
        public readonly bool $afterCommit = false,
        public readonly bool $batchable = false,
        public readonly array $middleware = [],
        public readonly array $dynamic = [],
    ) {}

    /**
     * Whether anything here is worth showing. A job that declares none of it is an ordinary job,
     * and a panel section listing six nulls says less than no section at all.
     */
    public function isInteresting(): bool
    {
        return $this->tries !== null
            || $this->timeout !== null
            || $this->backoff !== null
            || $this->maxExceptions !== null
            || $this->unique
            || $this->encrypted
            || $this->afterCommit
            || $this->batchable
            || $this->middleware !== []
            || $this->dynamic !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'queued' => $this->queued,
            'tries' => $this->tries,
            'timeout' => $this->timeout,
            'backoff' => $this->backoff,
            'maxExceptions' => $this->maxExceptions,
            'unique' => $this->unique,
            'uniqueUntilProcessing' => $this->uniqueUntilProcessing,
            'uniqueFor' => $this->uniqueFor,
            'encrypted' => $this->encrypted,
            'afterCommit' => $this->afterCommit,
            'batchable' => $this->batchable,
            'middleware' => $this->middleware,
            'dynamic' => $this->dynamic,
        ];
    }
}
