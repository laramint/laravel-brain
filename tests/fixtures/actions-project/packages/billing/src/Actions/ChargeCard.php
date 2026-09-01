<?php

namespace Billing\Actions;

/**
 * An action class in a package rather than under app/, reached by a glob pattern.
 */
class ChargeCard
{
    public function __invoke()
    {
        return true;
    }
}
