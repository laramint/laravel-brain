<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Throwable;

/**
 * Whether the application batches relation access instead of querying per model.
 *
 * `Model::automaticallyEagerLoadRelationships()` makes Eloquent load a relation for a whole
 * collection the first time any member of it is touched. Where it is on, the shape that the N+1
 * heuristic looks for — a relation read inside a loop — is not an N+1 at all, and flagging it
 * teaches people to ignore the marker.
 *
 * It arrived in Laravel 12.8, and this package supports 9 through 13, so the class is asked at
 * runtime through reflection rather than called outright. Both readable spellings break half the
 * matrix, in opposite directions: `method_exists(Model::class, '...')` is "always true" to
 * analysis running against 12.8 or later, and calling the method outright is an undefined static
 * method to analysis running against 11 or earlier. A local variable does not help — the name is
 * constant-folded back into the literal. Reflection is the form that asks the same question
 * without asserting an answer the analysed version has already decided.
 *
 * Asking the framework also beats scanning providers for the call, which would miss it being set
 * from a package, a config flag, or a conditional.
 */
class RelationAutoloading
{
    public static function isEnabled(): bool
    {
        try {
            $model = new ReflectionClass(Model::class);

            if (! $model->hasMethod('isAutomaticallyEagerLoadingRelationships')) {
                return false;
            }

            return (bool) $model->getMethod('isAutomaticallyEagerLoadingRelationships')->invoke(null);
        } catch (Throwable) {
            // No application booted — a scan run outside one, or a unit test. Answering "off"
            // keeps the heuristic at its historical strength rather than silently disarming it.
            return false;
        }
    }
}
