<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

/**
 * One place the application can be entered from outside itself.
 *
 * These are the roots of every call chain Brain traces: a request hits a route, the
 * scheduler runs a command, a worker picks up a queued listener. Nothing in the graph is
 * reachable except through one of them, which is what makes an inventory of them the only
 * honest denominator for "what does nothing reach".
 */
final class EntryPoint
{
    public const KIND_ROUTE = 'route';

    public const KIND_COMMAND = 'command';

    public const KIND_SCHEDULE = 'schedule';

    public const KIND_CHANNEL = 'channel';

    /**
     * A listener that implements ShouldQueue. A synchronous listener is *not* an entry point:
     * it runs inside the call chain of whoever dispatched its event, so it is reached, not a
     * root. A queued one is picked off the queue by a worker with no caller at all.
     */
    public const KIND_QUEUED_LISTENER = 'queued_listener';

    public const KIND_FILAMENT = 'filament';

    /**
     * Display order, so the tab reads the same way on every project instead of in whatever
     * order the analyzers happen to run.
     *
     * @var list<string>
     */
    public const KIND_ORDER = [
        self::KIND_ROUTE,
        self::KIND_COMMAND,
        self::KIND_SCHEDULE,
        self::KIND_CHANNEL,
        self::KIND_QUEUED_LISTENER,
        self::KIND_FILAMENT,
    ];

    /**
     * @param  string  $fqcn  the class that runs, or '' for a closure route / closure command
     * @param  list<string>  $nodeIds  graph nodes this entry point is known by. Routes,
     *                                 commands, channels and schedule entries have an id built
     *                                 from their signature rather than from a class, so the
     *                                 FQCN alone cannot find them.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $fqcn = '',
        public string $file = '',
        public array $nodeIds = [],
        public string $detail = '',
    ) {}
}
