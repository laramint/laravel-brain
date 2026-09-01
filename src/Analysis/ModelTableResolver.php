<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Throwable;

/**
 * Which table each model actually reads, asked of the model rather than inferred from its name.
 *
 * Parsing gets this right only for a model that says `protected $table` in its own file. Every
 * other route to a table name is invisible to a parser, and they are all common: a connection
 * prefix, a `$table` inherited from a base class, or — measured on the application this was built
 * against — a base model that overrides `getTable()` and prefixes every table in the codebase.
 * There the parser guessed `orders` for a table called `fm_orders`, and 181 of 183 models missed.
 *
 * `getTable()` is the one thing that cannot be wrong, because it is what Eloquent itself calls.
 * Reaching it means constructing the model, which is why this is separable, guarded per model,
 * and skipped entirely when the scan is configured not to consult the running application.
 */
class ModelTableResolver
{
    /**
     * Correct each definition's table name where the live class can answer for it.
     *
     * Anything that cannot be asked is left exactly as parsed: an abstract model, a class that is
     * not loadable, a constructor that throws. A wrong guess and a missing answer both leave the
     * definition untouched, so this can only improve on parsing, never regress it.
     *
     * @param  array<string, ModelDefinition>  $models
     * @return array<string, ModelDefinition>
     */
    public function resolve(array $models): array
    {
        foreach ($models as $fqcn => $definition) {
            $table = $this->tableFor((string) $fqcn);

            if ($table !== null && $table !== '') {
                $definition->table = $table;
            }
        }

        return $models;
    }

    private function tableFor(string $fqcn): ?string
    {
        try {
            if (! class_exists($fqcn)) {
                return null;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                return null;
            }

            $model = $reflection->newInstance();

            return $model instanceof Model ? $model->getTable() : null;
        } catch (Throwable) {
            // One model with a constructor that needs arguments, a trait booting against a
            // service that is not bound, or any other surprise, must not cost the other 182
            // their table names.
            return null;
        }
    }
}
