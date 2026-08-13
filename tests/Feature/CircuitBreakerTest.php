<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillWithFailingRow::reset());

it('stops a run whose failures have become systemic', function () {
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    $run = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    // Trips as soon as the sample is big enough to mean something: 50 rows in.
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->failed_count)->toBe(50)
        ->and($run->processed_count)->toBe(0)
        ->and($run->meta['stop_reason'])->toContain('Circuit breaker tripped');
});

it('ignores a failure rate measured on too few rows', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = range(1, 6);

    // Every row fails, but six rows is not evidence of anything systemic.
    $run = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 2]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->failed_count)->toBe(6);
});

it('lets a run finish when failures stay within tolerance', function () {
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 10);

    $run = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->failed_count)->toBe(10)
        ->and($run->processed_count)->toBe(50);
});

it('can be turned off', function () {
    config()->set('backfill.circuit_breaker.enabled', false);
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    $run = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->failed_count)->toBe(60);
});

it('respects a custom threshold', function () {
    config()->set('backfill.circuit_breaker.max_failure_rate', 0.05);
    config()->set('backfill.circuit_breaker.min_sample', 20);

    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 10);

    $run = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->meta['stop_reason'])->toContain('limit 5%');
});

it('leaves the paused run resumable so it can continue once fixed', function () {
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    $tripped = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    expect($tripped->status)->toBe(RunStatus::Paused);

    // Whatever was wrong is now fixed.
    BackfillWithFailingRow::$poisoned = [];

    $resumed = runBackfill(BackfillWithFailingRow::class, ['batchSize' => 10]);

    expect($resumed->id)->toBe($tripped->id)
        ->and($resumed->status)->toBe(RunStatus::Completed)
        ->and(User::whereNull('slug')->count())->toBe(50);
});
