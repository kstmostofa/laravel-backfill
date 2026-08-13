<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Testing\InteractsWithBackfills;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

uses(InteractsWithBackfills::class);

it('runs a backfill through the testing helper', function () {
    User::seedUnslugged(5);

    $run = $this->runBackfill(BackfillUserSlugs::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(5)
        ->and(User::whereNull('slug')->count())->toBe(0);
});

it('defaults to a batch size small enough to exercise pagination', function () {
    User::seedUnslugged(5);

    $run = $this->runBackfill(BackfillUserSlugs::class);

    expect($run->batch_size)->toBe(2)
        ->and($run->batch_count)->toBe(3);
});

it('accepts run options', function () {
    User::seedUnslugged(6);

    $run = $this->runBackfill(BackfillUserSlugs::class, ['batchSize' => 3, 'maxBatches' => 1]);

    expect($run->batch_size)->toBe(3)
        ->and($run->processed_count)->toBe(3)
        ->and($run->status)->toBe(RunStatus::Paused);
});
