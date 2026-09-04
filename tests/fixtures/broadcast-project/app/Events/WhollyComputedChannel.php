<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Both segments come from values; the only literal in the name is the separator. */
class WhollyComputedChannel implements ShouldBroadcast
{
    public function __construct(private readonly string $scope, private readonly string $ref) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->scope.'.'.$this->ref);
    }
}
