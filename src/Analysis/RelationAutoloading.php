<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Whether the application batches relation access instead of querying per model.
 *
 * `Model::automaticallyEagerLoadRelationships()` makes Eloquent load a relation for a whole
 * collection the first time any member of it is touched. Where it is on, the shape that the N+1
 * heuristic looks for — a relation read inside a loop — is not an N+1 at all, and flagging it
 * teaches people to ignore the marker.
 *
 * It arrived in Laravel 12.8, and this package supports 9 through 13. The call itself is the
 * version gate — on anything earlier there is no such method and the attempt throws, which is the
 * same answer as "off", because an application that cannot turn it on has not turned it on. A
 * `method_exists` guard would read better but cannot be written honestly: static analysis runs
 * against one installed version, where the method always exists.
 *
 * Asking the framework also beats scanning providers for the call, which would miss it being set
 * from a package, a config flag, or a conditional.
 */
class RelationAutoloading
{
    public static function isEnabled(): bool
    {
        try {
            return (bool) Model::isAutomaticallyEagerLoadingRelationships();
        } catch (Throwable) {
            // Laravel below 12.8, which has no such method, or no application booted at all — a
            // scan run outside one, or a unit test. Both mean the batching is not in effect, so
            // both answer "off", which keeps the heuristic at its historical strength rather than
            // silently disarming it.
            return false;
        }
    }
}
