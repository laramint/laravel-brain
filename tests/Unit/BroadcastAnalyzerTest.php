<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\BroadcastAnalyzer;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Analysis\BroadcastAnalyzer
 */
function broadcasts(): array
{
    return (new BroadcastAnalyzer(['app/Events']))->analyze(fixture('broadcast-project'));
}

function broadcastOf(string $short): object
{
    $all = broadcasts();
    $fqcn = 'App\\Events\\'.$short;

    if (! isset($all[$fqcn])) {
        throw new RuntimeException("{$short} does not broadcast");
    }

    return $all[$fqcn];
}

it('reads only the events that advertise themselves as broadcasting', function () {
    // PlainEvent implements nothing, so it is not a broadcast at all — the pass says nothing
    // about it rather than reporting an event with no channels.
    expect(array_keys(broadcasts()))->toBe([
        'App\\Events\\Announced',
        'App\\Events\\ChannelNobodyCanRead',
        'App\\Events\\OrderPinned',
        'App\\Events\\OrderShipped',
        'App\\Events\\RoomJoined',
        'App\\Events\\TeamFeedUpdated',
        'App\\Events\\WhollyComputedChannel',
    ]);
});

it('renders a channel built from a property with the property in its place', function () {
    // `'orders.'.$this->order->id` — the literal survives and the rest becomes a placeholder
    // named after the value, which is what makes it comparable to `orders.{orderId}` from
    // routes/channels.php.
    $channel = broadcastOf('OrderShipped')->channels[0];

    expect($channel->name)->toBe('orders.{id}')
        ->and($channel->kind)->toBe('private')
        ->and($channel->computed)->toBeFalse();
});

it('renders an interpolated channel the same as a concatenated one', function () {
    // `"presence-room.{$this->roomId}"` and `'presence-room.'.$this->roomId` are one channel,
    // and two spellings of it must not read as two.
    $channel = broadcastOf('RoomJoined')->channels[0];

    expect($channel->name)->toBe('presence-room.{roomId}')
        ->and($channel->kind)->toBe('presence');
});

it('reports a channel it cannot read as computed instead of guessing one', function () {
    $channel = broadcastOf('ChannelNobodyCanRead')->channels[0];

    expect($channel->computed)->toBeTrue()
        // The kind is still known: the class was written down even though the name was not.
        ->and($channel->kind)->toBe('private');
});

it('tells a queued broadcast from one sent there and then', function () {
    expect(broadcastOf('OrderShipped')->queued)->toBeTrue()
        ->and(broadcastOf('RoomJoined')->queued)->toBeFalse();
});

it('reads the name subscribers actually listen for', function () {
    expect(broadcastOf('OrderShipped')->alias)->toBe('order.shipped')
        ->and(broadcastOf('Announced')->alias)->toBeNull();
});

it('reports the promises an event makes about its payload and its queue', function () {
    $announced = broadcastOf('Announced');
    $room = broadcastOf('RoomJoined');

    expect($announced->conditional)->toBeTrue()
        ->and($announced->queue)->toBe('broadcasts')
        ->and($announced->customPayload)->toBeFalse()
        // RoomJoined declares broadcastWith(), so its payload is not its public properties.
        ->and($room->customPayload)->toBeTrue()
        ->and($room->conditional)->toBeFalse();
});
