<?php

namespace App\Services;

use App\Jobs\ProcessReport;
use Illuminate\Support\Facades\Bus;

class DynamicDispatcher
{
    public function run($job): void
    {
        dispatch(new ProcessReport);  // resolved (control)
        dispatch($job);               // unresolved: variable
        $this->dispatch($job);        // unresolved: $this->dispatch variable
        Bus::batch($this->pending()); // unresolved: non-array argument
    }

    // The only dispatch is a partially-resolvable chain: one literal job + one opaque entry.
    public function chainOnly($job): void
    {
        Bus::chain([new ProcessReport, $job]);
    }

    // The only dispatch is a single Bus::dispatch of an opaque job.
    public function busSingle($job): void
    {
        Bus::dispatch($job);
    }

    // Livewire/Filament event dispatches — string/array first arg, NOT queued jobs.
    public function livewireEvents(): void
    {
        $this->dispatch('saved');
        $this->dispatch('refresh', id: 1);
        dispatch();
    }

    public function withRetries($job): void
    {
        dispatch_with_retries(new ProcessReport); // resolved only when helper is configured
        dispatch_with_retries($job);              // unresolved only when helper is configured
    }

    private function pending(): array
    {
        return [];
    }
}
