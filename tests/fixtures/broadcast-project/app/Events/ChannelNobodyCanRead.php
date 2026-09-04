<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ChannelNobodyCanRead implements ShouldBroadcast
{
    public function __construct(private readonly string $topic) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->topic);
    }
}
