<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

/**
 * A class that exists in the source paths and that no traced call chain arrives at.
 *
 * The wording of every label built from this matters more than the data in it. "Nothing
 * reaches this from a traced entry point" is a statement about the tracer; "dead code" is a
 * statement about the application, and Brain is in no position to make it. A class resolved
 * out of the container, reached through a facade, named as a string in config, or built by
 * reflection is running in production while sitting on this list, and the moment a reader
 * deletes one of those on this report's say-so the report is finished as a tool.
 *
 * {@see $unfollowableReferences} is what keeps that distinction visible rather than
 * merely stated: each entry is a real reference Brain found and could not follow.
 */
final class UnreachedClass
{
    /** Named as the abstract or the concrete of a container binding found in a provider. */
    public const REFERENCE_CONTAINER_BINDING = 'container-binding';

    /** Is a facade, or the class a discovered facade resolves to. */
    public const REFERENCE_FACADE = 'facade';

    /** Named in a file under config/ — resolved by the framework long after any call chain. */
    public const REFERENCE_CONFIG = 'config';

    /** A class the tracer *did* reach extends it, implements it, or uses it as a trait. */
    public const REFERENCE_INHERITED = 'inherited-by-reached-class';

    /** Named as `Foo::class` or a quoted FQCN in some other source file. */
    public const REFERENCE_CLASS_STRING = 'class-string';

    /**
     * @param  list<string>  $unfollowableReferences  REFERENCE_* constants — every way Brain
     *                                                found this class named without a call to
     *                                                follow into it
     * @param  bool  $tracerBlind  true when the tracer has no edge type for this kind at all,
     *                             so its absence from the graph is expected rather than a finding
     */
    public function __construct(
        public string $fqcn,
        public string $file,
        public string $kind,
        public array $unfollowableReferences = [],
        public bool $tracerBlind = false,
    ) {}
}
