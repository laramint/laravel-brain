<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'orders:sync';

    protected $description = 'Sync orders';

    public function handle(): void
    {
        //
    }
}
