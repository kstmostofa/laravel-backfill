<?php

use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Facades\Backfills;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use Kstmostofa\Backfill\Testing\InteractsWithBackfills;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillOrderRefunds;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillPerTenant;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingCounts;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithBackfills::class);

beforeEach(function () {
    BackfillPerTenant::reset();
    BackfillWithFailingRow::reset();
});

it('records a run from the command line without touching the data', function () {
    Backfills::fake();
    User::seedUnslugged(3);

    $this->artisan('backfill:run', ['name' => 'user-slugs', '--force' => true])->assertSuccessful();

    Backfills::assertRan('user-slugs');
    Backfills::assertRan(BackfillUserSlugs::class);
    Backfills::assertRan('BackfillUserSlugs');

    expect(User::whereNull('slug')->count())->toBe(3)
        ->and(BackfillRun::count())->toBe(0);
});

it('records what backfill:pending would run', function () {
    Backfills::fake();
    onlyBackfills([BackfillPendingSlugs::class, BackfillPendingCounts::class]);

    $this->artisan('backfill:pending', ['--run' => true, '--force' => true])->assertSuccessful();

    Backfills::assertRan('pending-slugs');
    Backfills::assertRan('pending-counts');
    Backfills::assertNotRan('user-slugs');
});

it('records queued runs once the job executes', function () {
    Backfills::fake();

    (new RunBackfillJob('user-slugs', batchSize: 50))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    Backfills::assertRan('user-slugs', fn ($backfill, RunOptions $options) => $options->batchSize === 50);
});

it('checks runs against a callback', function () {
    Backfills::fake();

    app(BackfillRunner::class)->run(
        new BackfillOrderRefunds,
        new RunOptions(parameters: ['order_ids' => [1, 2]]),
    );

    Backfills::assertRan('order-refunds', fn ($backfill) => $backfill->parameter('order_ids') === [1, 2]);
    Backfills::assertNotRan('order-refunds', fn ($backfill) => $backfill->parameter('order_ids') === [3]);
});

it('records one run per tenant', function () {
    Backfills::fake();

    app(BackfillRunner::class)->runAll(new BackfillPerTenant);

    Backfills::assertRanTimes('per-tenant', 2);
    Backfills::assertRan('per-tenant', fn ($backfill, RunOptions $options) => $options->tenant === 'globex');
});

it('fails when an expected backfill did not run', function () {
    Backfills::fake();

    expect(fn () => Backfills::assertRan('user-slugs'))
        ->toThrow(AssertionFailedError::class, 'The expected backfill [user-slugs] was not run.');
});

it('fails when anything ran and nothing should have', function () {
    Backfills::fake();
    app(BackfillRunner::class)->run(new BackfillUserSlugs);

    expect(fn () => Backfills::assertNothingRan())
        ->toThrow(AssertionFailedError::class, 'user-slugs');
});

it('asserts on a real run', function () {
    User::seedUnslugged(5);

    $run = $this->runBackfill(BackfillUserSlugs::class);

    $this->assertBackfillCompleted($run);
    $this->assertBackfillProcessed($run, 5);
    $this->assertBackfillHasNoFailures($run);
});

it('names the first failed row when failures are not expected', function () {
    User::seedUnslugged(4);
    BackfillWithFailingRow::$poisoned = [3];

    $run = $this->runBackfill(BackfillWithFailingRow::class);

    expect(fn () => $this->assertBackfillHasNoFailures($run))
        ->toThrow(AssertionFailedError::class, 'The first, row 3, threw');
});

it('explains why a run did not complete', function () {
    User::seedUnslugged(6);

    $run = $this->runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    expect(fn () => $this->assertBackfillCompleted($run))
        ->toThrow(AssertionFailedError::class, 'ended as paused, not completed. Stopped after 1 batches');
});
