<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithDatabaseError;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\Tag;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillWithFailingRow::reset());

it('keeps going when a single row throws', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillWithFailingRow::class);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(5)
        ->and($run->failed_count)->toBe(1)
        ->and(User::whereNull('slug')->pluck('id')->all())->toBe([3]);
});

it('records the failure with enough detail to act on', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillWithFailingRow::class);

    $error = BackfillRunError::where('run_id', $run->id)->sole();

    expect($error->record_id)->toBe('3')
        ->and($error->exception_class)->toBe(RuntimeException::class)
        ->and($error->message)->toContain('Row 3 is poisoned')
        ->and($error->trace)->not->toBeEmpty();
});

it('calls the onRowFailed hook', function () {
    User::seedUnslugged(6);

    runBackfill(BackfillWithFailingRow::class);

    expect(BackfillWithFailingRow::$failedRows)->toBe([3]);
});

it('does not let a failed row roll back its batch mates', function () {
    User::seedUnslugged(4);

    // Rows 3 and 4 share a batch; only 3 is poisoned.
    $run = runBackfill(BackfillWithFailingRow::class);

    expect(User::find(4)->slug)->not->toBeNull()
        ->and(User::find(4)->process_count)->toBe(1)
        ->and(User::find(3)->slug)->toBeNull()
        ->and($run->failed_count)->toBe(1);
});

it('advances the cursor past a failed row so it cannot wedge the run', function () {
    User::seedUnslugged(4);

    $run = runBackfill(BackfillWithFailingRow::class);

    expect($run->cursor)->toBe('4')
        ->and($run->status)->toBe(RunStatus::Completed);
});

/**
 * The savepoint test proper. A row failing with a database error — not a PHP
 * exception — is what aborts the surrounding transaction on PostgreSQL, so
 * this is the only case that proves the per-row savepoints are doing anything.
 */
it('isolates a row that fails with a real database error', function () {
    Tag::seed(4);

    $run = runBackfill(BackfillWithDatabaseError::class);

    // One row takes the label, the other three collide with the unique index.
    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->processed_count)->toBe(1)
        ->and($run->failed_count)->toBe(3)
        ->and(Tag::whereNotNull('label')->count())->toBe(1);
});

it('still commits the error records when rows fail at the database level', function () {
    Tag::seed(4);

    $run = runBackfill(BackfillWithDatabaseError::class);

    // Without savepoints the aborted transaction would reject these inserts
    // too, and the whole batch — cursor included — would roll back.
    expect(BackfillRunError::where('run_id', $run->id)->count())->toBe(3)
        ->and($run->cursor)->not->toBeNull();
});

it('surfaces many failures without stopping', function () {
    BackfillWithFailingRow::$poisoned = [2, 3, 5];
    User::seedUnslugged(6);

    $run = runBackfill(BackfillWithFailingRow::class);

    expect($run->processed_count)->toBe(3)
        ->and($run->failed_count)->toBe(3)
        ->and(BackfillRunError::where('run_id', $run->id)->count())->toBe(3)
        ->and($run->status)->toBe(RunStatus::Completed);
});
