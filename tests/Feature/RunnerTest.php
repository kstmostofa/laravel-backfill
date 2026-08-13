<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithoutHydration;
use Kstmostofa\Backfill\Tests\Fixtures\User;

it('processes every row exactly once', function () {
    User::seedUnslugged(5);

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(5)
        ->and($run->failed_count)->toBe(0)
        ->and(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', '!=', 1)->count())->toBe(0);
});

it('paginates in batches rather than one big query', function () {
    User::seedUnslugged(5);

    // batchSize is 2, so 5 rows means 3 batches.
    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->batch_count)->toBe(3)
        ->and($run->batch_size)->toBe(2);
});

it('commits the cursor as it goes', function () {
    User::seedUnslugged(4);

    $seen = [];

    $run = runBackfill(BackfillUserSlugs::class, [
        'onBatch' => function (BackfillRun $run) use (&$seen) {
            $seen[] = $run->fresh()->cursor;
        },
    ]);

    expect($seen)->toBe(['2', '4'])
        ->and($run->cursor)->toBe('4');
});

it('records an estimate of the work up front', function () {
    User::seedUnslugged(7);

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->total_estimate)->toBe(7)
        ->and($run->progressPercent())->toBe(100.0);
});

it('can skip the estimate for tables too big to count', function () {
    User::seedUnslugged(3);

    $run = runBackfill(BackfillUserSlugs::class, ['withoutEstimate' => true]);

    expect($run->total_estimate)->toBeNull()
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(3);
});

it('supports the un-hydrated fast path', function () {
    User::seedUnslugged(5);

    $run = runBackfill(BackfillWithoutHydration::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(5)
        ->and(User::where('slug', 'bulk')->count())->toBe(5)
        ->and(User::where('process_count', 1)->count())->toBe(5);
});

it('honours a batch size passed at run time', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillUserSlugs::class, ['batchSize' => 3]);

    expect($run->batch_size)->toBe(3)
        ->and($run->batch_count)->toBe(2);
});

it('does nothing when the collection is already empty', function () {
    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(0)
        ->and($run->batch_count)->toBe(0)
        ->and($run->cursor)->toBeNull();
});

it('is safe to run twice — the second pass finds nothing to do', function () {
    User::seedUnslugged(4);

    runBackfill(BackfillUserSlugs::class);
    $second = runBackfill(BackfillUserSlugs::class, ['fresh' => true]);

    expect($second->processed_count)->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(4);
});
