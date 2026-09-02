<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Graph\GraphSplitter
 */

/**
 * A channel tab exists to answer one question — what goes out on this channel — and the events
 * that answer it point AT the channel, while the tab is grown forward from it. Without this the
 * edge is built, correct, and present in no tab a reader ever opens.
 */
beforeEach(function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['app' => ['name' => 'BroadcastTab'], 'laravel-brain' => [
        'broadcasting' => ['enabled' => true, 'paths' => ['app/Events']],
        'channel_paths' => ['routes/channels.php'],
    ]]));
});

afterEach(function () {
    Container::setInstance(null);
});

function broadcastTab(string $tabId): ?object
{
    $result = (new ProjectAnalyzer)->analyze(fixture('broadcast-project'), function () {});

    return $result->subgraphs[$tabId] ?? null;
}

it('shows the events that broadcast onto a channel in that channel’s tab', function () {
    $tab = broadcastTab('channel-orders-orderid');

    expect($tab)->not->toBeNull('the fixture channel has no tab');

    $events = [];

    foreach ($tab->nodes() as $node) {
        if ($node->type === 'event') {
            $events[] = $node->data['fqcn'] ?? $node->id;
        }
    }

    expect($events)->toContain('App\\Events\\OrderShipped');
});

it('does not drag the broadcasting event’s own subtree into the channel tab', function () {
    // The events are added node by node on purpose. Seeding a forward walk from them would grow
    // everything each event reaches into a tab that is about the channel.
    $tab = broadcastTab('channel-announcements');

    expect($tab)->not->toBeNull();

    $types = [];

    foreach ($tab->nodes() as $node) {
        $types[$node->type] = ($types[$node->type] ?? 0) + 1;
    }

    // The channel, and the one event that broadcasts on it. Nothing else rides in.
    expect($types)->toBe(['channel' => 1, 'event' => 1]);
});
