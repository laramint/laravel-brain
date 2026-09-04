<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', fn ($user, $orderId) => true);
Broadcast::channel('presence-room.{roomId}', fn ($user, $roomId) => true);
Broadcast::channel('announcements', fn ($user) => true);
Broadcast::channel('{tenant}.{stream}', fn ($user, $tenant, $stream) => true);
Broadcast::channel('orders.updates', fn ($user) => true);
