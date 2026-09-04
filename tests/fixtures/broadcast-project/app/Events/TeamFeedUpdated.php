<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** A placeholder where the declared channel has a literal — the mirror of OrderPinned. */
class TeamFeedUpdated implements ShouldBroadcast
{
    public function __construct(private readonly string $team) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->team.'.updates');
    }
}
