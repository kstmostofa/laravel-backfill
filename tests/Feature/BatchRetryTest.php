<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRunBatch;
use Kstmostofa\Backfill\Support\TransientFailure;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillThatDeadlocks;
use Kstmostofa\Backfill\Tests\Fixtures\FakeDeadlockException;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(function () {
    BackfillThatDeadlocks::reset();
    config()->set('backfill.retry.base_delay_ms', 1);
});

it('recognises a deadlock as worth retrying', function () {
    expect(TransientFailure::matches(new FakeDeadlockException))->toBeTrue();
});

it('does not retry an ordinary bug', function () {
    expect(TransientFailure::matches(new RuntimeException('column does not exist')))->toBeFalse();
});

it('sees through a wrapped transient failure', function () {
    $wrapped = new RuntimeException('batch failed', 0, new FakeDeadlockException);

    expect(TransientFailure::matches($wrapped))->toBeTrue();
});

it('retries a deadlocked batch until it succeeds', function () {
    User::seedUnslugged(6);
    BackfillThatDeadlocks::$deadlocksLeft = 2;

    $run = runBackfill(BackfillThatDeadlocks::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(6)
        ->and($run->failed_count)->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('does not double-count rows from a retried batch', function () {
    User::seedUnslugged(6);
    BackfillThatDeadlocks::$deadlocksLeft = 1;

    $run = runBackfill(BackfillThatDeadlocks::class);

    // The first attempt rolled back after incrementing the in-memory counters.
    // If those were not re-read, this would come back as 8.
    expect($run->processed_count)->toBe(6);
});

it('gives up once the retries are exhausted', function () {
    User::seedUnslugged(6);
    config()->set('backfill.retry.max_batch_retries', 2);
    BackfillThatDeadlocks::$deadlocksLeft = 99;

    $caught = null;

    try {
        runBackfill(BackfillThatDeadlocks::class);
    } catch (Throwable $e) {
        $caught = $e;
    }

    // Laravel wraps a concurrency error in its own exception type, so assert
    // on what the failure means rather than which class carried it.
    expect($caught)->not->toBeNull()
        ->and(TransientFailure::matches($caught))->toBeTrue();

    // Two retries after the first attempt: three calls, three deadlocks.
    expect(BackfillThatDeadlocks::$deadlocksLeft)->toBe(96);

    $run = \Kstmostofa\Backfill\Models\BackfillRun::latest('id')->first();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->cursor)->toBeNull()
        ->and(User::whereNotNull('slug')->count())->toBe(0);
});

it('fails a real bug immediately instead of retrying it', function () {
    User::seedUnslugged(6);
    BackfillThatDeadlocks::$bugsLeft = 99;

    expect(fn () => runBackfill(BackfillThatDeadlocks::class))
        ->toThrow(RuntimeException::class, 'column does not exist');

    // One attempt, not four: retrying a missing column just holds locks longer
    // to reach the same error.
    expect(BackfillThatDeadlocks::$afterBatchCalls)->toBe(1);
});

it('records the attempt count in the batch audit trail', function () {
    config()->set('backfill.record_batches', true);
    User::seedUnslugged(4);
    BackfillThatDeadlocks::$deadlocksLeft = 1;

    $run = runBackfill(BackfillThatDeadlocks::class);

    $batches = BackfillRunBatch::where('run_id', $run->id)->orderBy('id')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->attempts)->toBe(2)
        ->and($batches[1]->attempts)->toBe(1)
        ->and($batches[0]->count)->toBe(2)
        ->and($batches[0]->from_id)->not->toBeNull();
});

it('leaves the batch audit trail off by default', function () {
    User::seedUnslugged(4);

    $run = runBackfill(BackfillThatDeadlocks::class);

    expect(BackfillRunBatch::where('run_id', $run->id)->count())->toBe(0);
});
