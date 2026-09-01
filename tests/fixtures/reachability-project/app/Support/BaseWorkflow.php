<?php

namespace App\Support;

/**
 * Unreached, and the parent of a class that is reached — the tracer only records a hop to a
 * base class where a call happens to resolve there, so inheritance alone leaves no edge.
 */
abstract class BaseWorkflow
{
    public function name(): string
    {
        return static::class;
    }
}
