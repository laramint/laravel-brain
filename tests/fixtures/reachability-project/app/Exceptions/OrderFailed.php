<?php

namespace App\Exceptions;

/**
 * Thrown, never called. Brain has no edge type that could arrive here, which is why this
 * lands in the "outside what the tracer follows" section rather than beside the jobs.
 */
class OrderFailed extends \RuntimeException
{
    //
}
