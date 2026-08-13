<?php

use Illuminate\Support\Facades\Event;
use Kstmostofa\Backfill\Enums\StopReason;
use Kstmostofa\Backfill\Events\BackfillCompleted;
use Kstmostofa\Backfill\Events\BackfillFailed;
use Kstmostofa\Backfill\Events\BackfillPaused;
use Kstmostofa\Backfill\Events\BackfillResumed;
use Kstmostofa\Backfill\Events\BackfillStarted;
use Kstmostofa\Backfill\Events\BatchProcessed;
use Kstmostofa\Backfill\Events\RowFailed;
use Kstmostofa\Backfill\Events\ThrottleEngaged;
use Kstmostofa\Backfill\Runner\LagMonitor;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillThatDeadlocks;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\FakeLagMonitor;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(function () {
    BackfillWithFailingRow::reset();
    BackfillThatDeadlocks::reset();
});

it('announces the start and the finish', function () {
    Event::fake([BackfillStarted::class, BackfillCompleted::class, BackfillResumed::class]);

    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Event::assertDispatched(BackfillStarted::class, fn ($e) => $e->resumed === false);
    Event::assertDispatched(BackfillCompleted::class);
    Event::assertNotDispatched(BackfillResumed::class);
});

it('fires one event per committed batch', function () {
    Event::fake([BatchProcessed::class]);

    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class);

    Event::assertDispatchedTimes(BatchProcessed::class, 3);
    Event::assertDispatched(BatchProcessed::class, fn ($e) => $e->outcome->processed === 2);
});

it('marks a resumed run as resumed', function () {
    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    Event::fake([BackfillStarted::class, BackfillResumed::class]);

    runBackfill(BackfillUserSlugs::class);

    Event::assertDispatched(BackfillStarted::class, fn ($e) => $e->resumed === true);
    Event::assertDispatched(BackfillResumed::class, fn ($e) => $e->cursor === '2');
});

it('reports each failed row', function () {
    Event::fake([RowFailed::class]);

    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [3, 5];

    runBackfill(BackfillWithFailingRow::class);

    Event::assertDispatchedTimes(RowFailed::class, 2);
    Event::assertDispatched(RowFailed::class, fn ($e) => $e->recordId === '3'
        && $e->exception instanceof RuntimeException
        && $e->record->id === 3);
});

it('reports a failed run with the exception', function () {
    Event::fake([BackfillFailed::class]);

    User::seedUnslugged(4);
    BackfillThatDeadlocks::$bugsLeft = 99;

    expect(fn () => runBackfill(BackfillThatDeadlocks::class))->toThrow(RuntimeException::class);

    Event::assertDispatched(BackfillFailed::class, fn ($e) => str_contains($e->exception->getMessage(), 'column does not exist'));
});

it('distinguishes an automatic pause from an operator one', function () {
    Event::fake([BackfillPaused::class]);

    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    Event::assertDispatched(BackfillPaused::class, function ($e) {
        return $e->reason === StopReason::CircuitBreaker
            && $e->wasAutomatic()
            && str_contains($e->message, 'Circuit breaker');
    });
});

it('does not treat a requested stop as an automatic pause', function () {
    Event::fake([BackfillPaused::class]);

    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    Event::assertDispatched(BackfillPaused::class, function ($e) {
        return $e->reason === StopReason::MaxBatches && ! $e->wasAutomatic();
    });
});

it('announces the throttle engaging', function () {
    Event::fake([ThrottleEngaged::class]);

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

    $this->app->instance(LagMonitor::class, new FakeLagMonitor([10.0]));

    runBackfill(BackfillUserSlugs::class);

    Event::assertDispatched(ThrottleEngaged::class, fn ($e) => $e->decision->lagSeconds === 10.0);
});

it('records a machine-readable stop code alongside the sentence', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    expect($run->meta['stop_code'])->toBe('max_batches')
        ->and($run->meta['stop_reason'])->toContain('as requested')
        ->and(StopReason::from($run->meta['stop_code'])->isAutomaticallyResumable())->toBeTrue();
});
