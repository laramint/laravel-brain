<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Everything a `Schedule::command('reports:nightly')` call says about WHEN and HOW the task
 * runs — read off the `->dailyAt('05:30')->withoutOverlapping()` tail that wraps it.
 *
 * A cadence method name on its own does not answer the question the schedule list exists to
 * answer: `dailyAt` is not a time and `cron` is not an expression. The arguments are the
 * answer, so they are carried alongside the method that took them.
 */
class ScheduleChain
{
    /**
     * @param  string[]  $frequencyArguments  Literal arguments of the cadence call — ['05:30'] for dailyAt('05:30').
     * @param  string[]  $modifiers  Guard methods present on the chain, in the order they were written.
     */
    public function __construct(
        public string $frequency = '',
        public array $frequencyArguments = [],
        public array $modifiers = [],
        public string $timezone = '',
    ) {}
}
