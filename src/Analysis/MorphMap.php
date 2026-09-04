<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * The short names an application stores in its `*_type` columns.
 *
 * `Relation::morphMap([...])` gives a model an alias, and from then on the alias — not the class
 * name — is what lands in the database, what a query filters on, and what a bug report quotes.
 * Someone holding a `morphable_type` of `parcel` had no way to get from that string to the model
 * through the graph, which is the one place that knows every model in the project.
 *
 * Read from the running application rather than parsed out of service providers. A scan always
 * runs against `base_path()` (`ScanCommand`, `BrainController`), so the app doing the scanning is
 * the app being scanned and the registered map IS the answer — including the entries a package
 * registered in its own provider, a `config()` value supplied, and a branch only one environment
 * takes. Parsing providers for `morphMap([...])` literals sees a strict subset of that and cannot
 * tell that it is missing anything, so a model would silently render as unaliased while the
 * database is full of its alias.
 */
final class MorphMap
{
    /** @var array<string, string> model FQCN => alias */
    private readonly array $aliasByClass;

    /**
     * @param  array<array-key, string>  $map  alias => model FQCN, the shape `Relation` stores
     * @param  bool  $enforced  whether `requireMorphMap()` / `enforceMorphMap()` is on
     */
    public function __construct(array $map = [], private readonly bool $enforced = false)
    {
        $aliases = [];
        foreach ($map as $alias => $class) {
            // `Model::getMorphClass()` answers with `array_search()`, which returns the FIRST key
            // holding the class. A model listed under two aliases therefore persists under the
            // earlier one, and naming the later one here would name a value the database has
            // never contained.
            $aliases[ltrim($class, '\\')] ??= (string) $alias;
        }

        $this->aliasByClass = $aliases;
    }

    /**
     * Ask the framework what it has registered.
     *
     * `Relation::$morphMap` is a plain static, so with no application booted — a unit test, or a
     * scan run outside one — it simply reads empty, which is already the safe answer: no aliases
     * to show, and nothing flagged as missing one. The catch covers the rest. A scan that has
     * already read an entire project should not end on a state read.
     *
     * @param  bool  $enabled  `laravel-brain.morph_map.enabled`. The switch is here, ahead of the
     *                         only line in this package that touches framework state, rather than
     *                         at the caller filtering the result afterwards: turning it off is
     *                         meant to mean the scanner never asks, and a gate that reads first
     *                         and discards second would not deliver that.
     */
    public static function fromApplication(bool $enabled = true): self
    {
        if (! $enabled) {
            return new self;
        }

        try {
            return new self(Relation::morphMap(), (bool) Relation::requiresMorphMap());
        } catch (Throwable) {
            return new self;
        }
    }

    public function aliasFor(string $fqcn): ?string
    {
        return $this->aliasByClass[ltrim($fqcn, '\\')] ?? null;
    }

    /**
     * Where this is on, a model missing from the map is not merely undocumented: the first
     * `getMorphClass()` call on it throws `ClassMorphViolationException`.
     */
    public function isEnforced(): bool
    {
        return $this->enforced;
    }
}
