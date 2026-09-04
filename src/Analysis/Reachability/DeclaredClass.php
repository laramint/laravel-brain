<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

/**
 * One class-like declaration found in the configured source paths.
 *
 * The inventory half of reachability needs a list of what *exists* before it can say what
 * nothing reaches, and no such list exists anywhere else in Brain: every other analyzer
 * enumerates one shape — models, controllers, Filament pages — because that is all its own
 * question needs.
 */
final class DeclaredClass
{
    /**
     * @param  string  $surface  class | abstract_class | interface | trait | enum — what the
     *                           declaration is, as opposed to what it is for
     * @param  string  $kind  the grouping name, see {@see ClassInventory::kindOf()}
     * @param  list<string>  $interfaces  resolved FQCNs from the `implements` clause
     * @param  list<string>  $traits  resolved FQCNs from `use` inside the body
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public string $surface,
        public string $kind,
        public string $parent = '',
        public array $interfaces = [],
        public array $traits = [],
    ) {}
}
