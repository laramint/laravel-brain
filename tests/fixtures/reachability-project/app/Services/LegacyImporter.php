<?php

namespace App\Services;

use App\Contracts\Importer;

/**
 * Unreached, and bound in a service provider — the container builds it, and the tracer has
 * no call to follow into it.
 */
class LegacyImporter implements Importer
{
    public function import(): void
    {
        //
    }
}
