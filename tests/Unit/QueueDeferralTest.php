<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use LaraMint\LaravelBrain\Analysis\QueueDeferral;

class SyncProbeListener
{
    public function handle(object $event): void {}
}

class QueuedProbeListener implements ShouldQueue
{
    public function handle(object $event): void {}
}

it('reports not observable when the event defers itself, whatever listens to it', function () {
    $deferral = new QueueDeferral(defersByDefault: false);

    expect($deferral->observableBeforeCommit(true, [SyncProbeListener::class]))->toBeFalse();
});

it('reports not observable when nothing listens, because nobody can act early', function () {
    // Measured on a real application: two of six candidate findings were events with no listener
    // at all. Reporting those teaches people the channel is noise.
    $deferral = new QueueDeferral(defersByDefault: false);

    expect($deferral->observableBeforeCommit(false, []))->toBeFalse();
});

it('reports not observable when every listener is queued and the queue waits for the commit', function () {
    // The case a class-level check cannot see: nothing on the event or the listener says this,
    // only `after_commit` in queue.php.
    $deferral = new QueueDeferral(defersByDefault: true);

    expect($deferral->observableBeforeCommit(false, [QueuedProbeListener::class]))->toBeFalse();
});

it('reports observable when a listener is queued but the queue does not wait', function () {
    $deferral = new QueueDeferral(defersByDefault: false);

    expect($deferral->observableBeforeCommit(false, [QueuedProbeListener::class]))->toBeTrue();
});

it('reports observable when a listener runs synchronously, however the queue is configured', function () {
    // A synchronous listener runs inside the dispatching request, so it sees the write before the
    // commit no matter what `after_commit` says.
    $deferral = new QueueDeferral(defersByDefault: true);

    expect($deferral->observableBeforeCommit(false, [SyncProbeListener::class]))->toBeTrue();
});

it('reports observable when one listener of several is synchronous', function () {
    $deferral = new QueueDeferral(defersByDefault: true);

    expect($deferral->observableBeforeCommit(false, [QueuedProbeListener::class, SyncProbeListener::class]))->toBeTrue();
});

it('reads ShouldQueue through inheritance', function () {
    $deferral = new QueueDeferral(defersByDefault: true);

    expect($deferral->isQueued(QueuedProbeListener::class))->toBeTrue()
        ->and($deferral->isQueued(SyncProbeListener::class))->toBeFalse()
        ->and($deferral->isQueued('No\\Such\\Listener'))->toBeFalse();
});
