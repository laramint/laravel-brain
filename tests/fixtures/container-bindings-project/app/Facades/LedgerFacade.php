<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * A facade fronting a bare container alias — the input FacadeRegistry::resolveWith() was
 * written for and could never be handed before bare keys were recorded.
 */
class LedgerFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ledger';
    }
}
