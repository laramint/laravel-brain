<?php

namespace App\Actions;

/**
 * A queued job that happens to live under Actions/. Recognised as a job, and staying one
 * is the point: "job" says more about it than "action class" does.
 */
class SendInvoiceJob
{
    public function handle()
    {
        return true;
    }
}
