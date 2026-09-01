<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPageDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPanelDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentResourceDefinition;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;

/**
 * Gathers the roots a build has already discovered into one list.
 *
 * Nothing here scans: every one of these came out of an analyzer that ran anyway, and the
 * only work left is to name them in a single vocabulary so the reachability walk can seed
 * from all of them at once. That is also why an entry point that a build did not discover is
 * simply absent — this cannot see further than the analyzers do, and pretending otherwise
 * would inflate the "reached" side with roots that were never traced.
 */
final class EntryPointInventory
{
    /**
     * Laravel's marker for a listener the queue picks up rather than the dispatcher running
     * inline. Matched by name: Brain never loads application code, so there is no
     * `is_subclass_of` to ask.
     */
    private const SHOULD_QUEUE = 'Illuminate\Contracts\Queue\ShouldQueue';

    /**
     * @param  RouteDefinition[]  $routes
     * @param  ConsoleCommandDefinition[]  $commands
     * @param  ScheduleEntry[]  $schedules
     * @param  ChannelDefinition[]  $channels
     * @param  CallChainEdge[]  $listenerEdges  event → listener edges, as ListenerAnalyzer returns them
     * @param  FilamentPanelDefinition[]  $filamentPanels
     * @param  FilamentResourceDefinition[]  $filamentResources
     * @param  FilamentPageDefinition[]  $filamentPages
     * @return list<EntryPoint>
     */
    public static function collect(
        array $routes = [],
        array $commands = [],
        array $schedules = [],
        array $channels = [],
        array $listenerEdges = [],
        array $filamentPanels = [],
        array $filamentResources = [],
        array $filamentPages = [],
        ?ClassInventory $classes = null,
    ): array {
        $entryPoints = [];

        foreach ($routes as $route) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_ROUTE,
                label: trim("{$route->method} {$route->uri}"),
                fqcn: $route->controller,
                file: $route->file,
                nodeIds: ["route::{$route->method}::{$route->uri}"],
                detail: $route->name,
            );
        }

        foreach ($commands as $command) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_COMMAND,
                label: $command->signature,
                fqcn: $command->class,
                file: $command->file,
                nodeIds: ["command::{$command->signature}"],
                detail: $command->description,
            );
        }

        foreach ($schedules as $schedule) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_SCHEDULE,
                label: $schedule->frequency !== ''
                    ? "{$schedule->target} ({$schedule->frequency})"
                    : $schedule->target,
                // A `->job(Foo::class)` entry names a class; a `->command()` one names a
                // signature, and the command it points at is already its own entry point.
                fqcn: $schedule->type === 'job' ? $schedule->target : '',
                file: $schedule->file,
                nodeIds: ['schedule::'.md5($schedule->type.$schedule->target.$schedule->frequency)],
                detail: $schedule->type,
            );
        }

        foreach ($channels as $channel) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_CHANNEL,
                label: $channel->name,
                fqcn: $channel->class,
                file: $channel->file,
                nodeIds: ['channel::'.md5($channel->name)],
            );
        }

        foreach (self::queuedListeners($listenerEdges, $classes) as $fqcn => $events) {
            $declared = $classes?->get($fqcn);
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_QUEUED_LISTENER,
                label: self::shortName($fqcn),
                fqcn: $fqcn,
                file: $declared !== null ? $declared->file : '',
                detail: 'queued on '.implode(', ', array_map(
                    static fn (string $event): string => self::shortName($event),
                    $events,
                )),
            );
        }

        foreach ($filamentPanels as $panel) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_FILAMENT,
                label: $panel->id !== '' ? "panel: {$panel->id}" : self::shortName($panel->fqcn),
                fqcn: $panel->fqcn,
                file: $panel->file,
                nodeIds: ["filament_panel::{$panel->fqcn}"],
                detail: 'panel',
            );
        }

        foreach ($filamentResources as $resource) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_FILAMENT,
                label: self::shortName($resource->fqcn),
                fqcn: $resource->fqcn,
                file: $resource->file,
                nodeIds: ["filament_resource::{$resource->fqcn}"],
                detail: 'resource',
            );
        }

        foreach ($filamentPages as $page) {
            $entryPoints[] = new EntryPoint(
                kind: EntryPoint::KIND_FILAMENT,
                label: $page->route !== '' ? $page->route : self::shortName($page->fqcn),
                fqcn: $page->fqcn,
                file: $page->file,
                nodeIds: ["filament_page::{$page->fqcn}"],
                detail: 'page',
            );
        }

        return $entryPoints;
    }

    /**
     * Listener FQCN => the events it handles, for listeners the queue runs.
     *
     * A listener Brain cannot find a declaration for is left out rather than guessed at: the
     * whole distinction rests on `implements ShouldQueue`, and a listener whose file was never
     * scanned has no such evidence either way. Counting it as a root would let anything it
     * calls pass as reached on nothing more than a missing file.
     *
     * @param  CallChainEdge[]  $listenerEdges
     * @return array<string, list<string>>
     */
    private static function queuedListeners(array $listenerEdges, ?ClassInventory $classes): array
    {
        if ($classes === null) {
            return [];
        }

        $queued = [];
        foreach ($listenerEdges as $edge) {
            if ($edge->type !== 'listener') {
                continue;
            }
            if (! self::implementsShouldQueue($edge->calleeFqcn, $classes)) {
                continue;
            }
            $queued[$edge->calleeFqcn][$edge->callerFqcn] = true;
        }

        return array_map(
            static fn (array $events): array => array_keys($events),
            $queued,
        );
    }

    private static function implementsShouldQueue(string $fqcn, ClassInventory $classes): bool
    {
        $seen = [];

        while ($fqcn !== '' && ! isset($seen[$fqcn])) {
            $seen[$fqcn] = true;
            $declared = $classes->get($fqcn);
            if ($declared === null) {
                return false;
            }
            if (in_array(self::SHOULD_QUEUE, $declared->interfaces, true)) {
                return true;
            }
            $fqcn = $declared->parent;
        }

        return false;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
