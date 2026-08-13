<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Runner\LagMonitor;
use Kstmostofa\Backfill\Runner\Throttle;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\FakeLagMonitor;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * @param  array<int, float|null>  $readings
 */
function throttleWith(array $readings, array $config = []): Throttle
{
    config()->set('backfill.throttle', array_merge([
        'enabled' => true,
        'connection' => null,
        'lag_soft' => 5,
        'lag_hard' => 30,
        'lag_timeout' => 1,
        'poll_ms' => 50,
        'min_batch_size' => 50,
        'slow_batch_multiplier' => 5,
    ], $config));

    return new Throttle(new FakeLagMonitor($readings));
}

it('does nothing at all when disabled', function () {
    $throttle = throttleWith([100.0], ['enabled' => false]);

    $decision = $throttle->evaluate(100, 1000);

    expect($decision->sleepMs)->toBe(100)
        ->and($decision->batchSize)->toBe(1000)
        ->and($decision->engaged())->toBeFalse();
});

it('runs at full speed while lag is healthy', function () {
    $decision = throttleWith([1.0])->evaluate(100, 1000);

    expect($decision->sleepMs)->toBe(100)
        ->and($decision->batchSize)->toBe(1000)
        ->and($decision->engaged())->toBeFalse();
});

it('eases off between the soft and hard thresholds', function () {
    $decision = throttleWith([10.0])->evaluate(100, 1000);

    expect($decision->sleepMs)->toBeGreaterThan(100)
        ->and($decision->batchSize)->toBe(500)
        ->and($decision->pause)->toBeFalse()
        ->and($decision->reason)->toContain('above the 5.0s soft limit');
});

it('slows down more the closer lag gets to the hard limit', function () {
    $mild = throttleWith([6.0])->evaluate(100, 1000);
    $severe = throttleWith([29.0])->evaluate(100, 1000);

    expect($severe->sleepMs)->toBeGreaterThan($mild->sleepMs);
});

it('never shrinks the batch below the configured floor', function () {
    $decision = throttleWith([10.0], ['min_batch_size' => 200])->evaluate(100, 300);

    expect($decision->batchSize)->toBe(200);
});

it('waits for replicas to recover, then carries on', function () {
    // Above hard, then recovered by the time it polls again.
    $decision = throttleWith([40.0, 1.0])->evaluate(100, 1000);

    expect($decision->pause)->toBeFalse()
        ->and($decision->batchSize)->toBe(1000);
});

it('pauses when the replicas never catch up', function () {
    $decision = throttleWith([40.0])->evaluate(100, 1000);

    expect($decision->pause)->toBeTrue()
        ->and($decision->reason)->toContain('Replication lag stayed at 40.0s')
        ->and($decision->lagSeconds)->toBe(40.0);
});

it('treats an unreadable lag signal as healthy rather than stalling', function () {
    // A missing REPLICATION CLIENT grant should not wedge a backfill.
    $decision = throttleWith([])->evaluate(100, 1000);

    expect($decision->pause)->toBeFalse()
        ->and($decision->engaged())->toBeFalse();
});

it('backs off when a batch suddenly runs long', function () {
    $decision = throttleWith([1.0])->evaluate(100, 1000, medianMs: 20, lastDurationMs: 500);

    expect($decision->sleepMs)->toBe(200)
        ->and($decision->batchSize)->toBe(500)
        ->and($decision->reason)->toContain('against a 20ms median');
});

it('ignores batch duration noise below the multiplier', function () {
    $decision = throttleWith([1.0])->evaluate(100, 1000, medianMs: 20, lastDurationMs: 60);

    expect($decision->engaged())->toBeFalse();
});

it('pauses a real run when lag will not recover', function () {
    User::seedUnslugged(6);

    config()->set('backfill.throttle', [
        'enabled' => true,
        'connection' => null,
        'lag_soft' => 5,
        'lag_hard' => 30,
        'lag_timeout' => 1,
        'poll_ms' => 50,
        'min_batch_size' => 1,
        'slow_batch_multiplier' => 5,
    ]);

    $this->app->instance(LagMonitor::class, new FakeLagMonitor([90.0]));

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->meta['stop_reason'])->toContain('Replication lag stayed')
        // The batch that had already committed is kept; the run resumes there.
        ->and($run->processed_count)->toBe(2)
        ->and($run->cursor)->toBe('2');
});

it('reports lag as null when the driver has nothing to report', function () {
    // SQLite has no replication to measure, so the real monitor returns null.
    expect(app(LagMonitor::class)->lagSeconds())->toBeNull();
});
