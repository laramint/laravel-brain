<?php

namespace App\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class RoomJoined implements ShouldBroadcastNow
{
    public function __construct(public readonly string $roomId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("presence-room.{$this->roomId}");
    }

    public function broadcastWith(): array
    {
        return ['room' => $this->roomId];
    }
}
