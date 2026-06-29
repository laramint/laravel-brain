<?php

namespace App\Listeners;

use App\Events\PodcastProcessed;
use Illuminate\Events\Attributes\AsEventListener;

// Registered only via the attribute; notify() carries no event type-hint, so
// the event comes from the attribute argument, not convention.
class SendPodcastNotification
{
    #[AsEventListener(event: PodcastProcessed::class)]
    public function notify(): void {}
}
