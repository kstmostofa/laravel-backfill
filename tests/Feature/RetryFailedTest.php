<?php

use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillWithFailingRow::reset());

it('re-processes only the rows that failed', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [2, 4];

    $run = runBackfill(BackfillWithFailingRow::class);

    expect($run->failed_count)->toBe(2)
        ->and(User::whereNull('slug')->pluck('id')->all())->toBe([2, 4]);

    // Whatever was wrong is fixed.
    BackfillWithFailingRow::$poisoned = [];

    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row'])
        ->assertSuccessful();

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('marks the errors resolved and corrects the counters', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [2, 4];

    $run = runBackfill(BackfillWithFailingRow::class);
    BackfillWithFailingRow::$poisoned = [];

    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row'])->assertSuccessful();

    expect(BackfillRunError::where('run_id', $run->id)->whereNull('resolved_at')->count())->toBe(0)
        ->and($run->fresh()->failed_count)->toBe(0)
        ->and($run->fresh()->processed_count)->toBe(6);
});

it('reports rows that are still failing', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [2, 4];

    runBackfill(BackfillWithFailingRow::class);

    // Nothing fixed, so the retry fails again.
    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row'])
        ->assertFailed();

    $error = BackfillRunError::where('record_id', '2')->first();

    expect($error->attempts)->toBe(2)
        ->and($error->resolved_at)->toBeNull();
});

it('respects a limit', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [2, 4];

    $run = runBackfill(BackfillWithFailingRow::class);
    BackfillWithFailingRow::$poisoned = [];

    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row', '--limit' => 1])
        ->assertSuccessful();

    expect(BackfillRunError::where('run_id', $run->id)->whereNull('resolved_at')->count())->toBe(1)
        ->and(User::whereNull('slug')->count())->toBe(1);
});

it('resolves an error whose row has since been deleted', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [2];

    $run = runBackfill(BackfillWithFailingRow::class);

    User::find(2)->delete();
    BackfillWithFailingRow::$poisoned = [];

    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row'])->assertSuccessful();

    expect(BackfillRunError::where('run_id', $run->id)->whereNull('resolved_at')->count())->toBe(0);
});

it('says so when there is nothing to retry', function () {
    User::seedUnslugged(4);
    BackfillWithFailingRow::$poisoned = [];

    runBackfill(BackfillWithFailingRow::class, ['batchSize' => 2]);

    $this->artisan('backfill:retry-failed', ['name' => 'with-failing-row'])
        ->expectsOutputToContain('no failed rows')
        ->assertSuccessful();
});

it('fails when the backfill has never run', function () {
    $this->artisan('backfill:retry-failed', ['name' => 'user-slugs'])
        ->assertFailed();
});
