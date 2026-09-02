<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class Announced implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new Channel('announcements');
    }

    public function broadcastWhen(): bool
    {
        return true;
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
