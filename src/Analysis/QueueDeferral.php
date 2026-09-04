<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Whether work reaches the database only after the transaction that scheduled it has committed.
 *
 * Two independent mechanisms answer this, and reading only one of them produces confident
 * nonsense:
 *
 *  - `ShouldDispatchAfterCommit` on an **event** holds the event itself until commit.
 *  - `after_commit` on a **queue connection** holds every queued job and queued listener, whether
 *    or not anything on them asks for it. It is a `queue.php` setting, not a class-level one, so
 *    no amount of reading the class will find it.
 *
 * The second is easy to miss and changes the answer wholesale. Measured on the application this
 * was built against: **all eight queue connections set `after_commit => true`**, so every queued
 * listener there is already deferred and only synchronous ones are exposed. A check that looked
 * only at the event class reported 6 problems where 2 are real — the other four were a queued
 * listener already covered by that setting, and events nothing listens to at all.
 */
class QueueDeferral
{
    private bool $defersByDefault;

    public function __construct(?bool $defersByDefault = null)
    {
        $this->defersByDefault = $defersByDefault ?? self::readFromConfig();
    }

    /**
     * Whether the default queue connection holds dispatches until the transaction commits.
     *
     * Read from the connection the application actually uses. A per-connection reading would be
     * more precise, but a listener does not say which connection it will run on unless it
     * declares one, so the default is the honest answer to the question being asked.
     */
    private static function readFromConfig(): bool
    {
        try {
            $default = config('queue.default');

            if (! is_string($default) || $default === '') {
                return false;
            }

            return (bool) config("queue.connections.{$default}.after_commit", false);
        } catch (Throwable) {
            // No container, no config, no idea — and "no idea" must not read as "exposed", or a
            // scan without a booted application invents findings.
            return true;
        }
    }

    public function queuedWorkIsDeferred(): bool
    {
        return $this->defersByDefault;
    }

    /**
     * Whether a listener runs on a queue rather than in the dispatching request.
     *
     * Asked of the class, because `ShouldQueue` is as often inherited from a base listener as
     * written on the listener itself.
     */
    public function isQueued(string $listenerFqcn): bool
    {
        try {
            return class_exists($listenerFqcn) && is_subclass_of($listenerFqcn, ShouldQueue::class);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a consumer of this event could act before the transaction that fired it commits.
     *
     * A precondition, deliberately not a finding. It says what would happen **if** the event were
     * dispatched inside a transaction; it does not claim that it is. Answering that needs the
     * transaction boundaries themselves, and reporting this as a problem without them would raise
     * one on every event that merely could be dispatched that way — measured here, 52 of 211.
     *
     * Any one of three things makes it safe:
     *
     *  - the event defers itself, or
     *  - every listener is queued and the queue defers, or
     *  - nothing listens at all, so there is nobody to observe it early.
     *
     * @param  list<string>  $listeners
     */
    public function observableBeforeCommit(bool $eventDefers, array $listeners): bool
    {
        if ($eventDefers) {
            return false;
        }

        // No explicit guard for an empty list: nothing to iterate means nothing exposed, which is
        // the same answer. A guard here would read as a rule and be dead code — removing it
        // changes no behaviour, and a test asserting it would pass either way.
        foreach ($listeners as $listener) {
            $deferred = $this->isQueued($listener) && $this->defersByDefault;

            if (! $deferred) {
                return true;
            }
        }

        return false;
    }
}
