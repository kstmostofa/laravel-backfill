<?php

use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillThatSelfDestructs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * The invariant the whole package is built around:
 *
 *   A backfill can be killed at any instant, restarted, and must arrive at the
 *   same end state — no duplicated side effects, no skipped rows.
 *
 * This does not simulate a crash. It forks, and the child sends itself a real
 * SIGKILL from inside a batch transaction. SIGKILL cannot be caught, so no
 * destructors, no shutdown handlers, no `finally` — the same thing the OOM
 * killer or a `kill -9` during a deploy does to a worker.
 */
beforeEach(function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('The chaos test needs the pcntl and posix extensions.');
    }

    BackfillThatSelfDestructs::$killOnBatch = 0;
});

it('resumes after a SIGKILL to the identical end state', function () {
    // 1. Establish the control: what an uninterrupted run produces.
    User::seedUnslugged(9);
    $control = runBackfill(BackfillThatSelfDestructs::class);
    $expected = userStateByName();

    expect($control->status)->toBe(RunStatus::Completed)
        ->and($expected)->toHaveCount(9);

    // 2. Rewind to the same starting point.
    User::query()->delete();
    BackfillRun::query()->delete();
    DB::table('backfill_run_errors')->delete();
    DB::table('backfill_locks')->delete();
    User::seedUnslugged(9);

    // Ids continue from the control run rather than restarting, so derive the
    // expected cursor from the rows themselves.
    $ids = User::query()->orderBy('id')->pluck('id')->all();
    $endOfFirstBatch = (string) $ids[1];

    // 3. Fork a child that kills itself part-way through the second batch.
    BackfillThatSelfDestructs::$killOnBatch = 2;

    $pid = pcntl_fork();

    if ($pid === -1) {
        $this->markTestSkipped('Could not fork.');
    }

    if ($pid === 0) {
        // Child. A fresh connection so we are not writing through a file
        // descriptor shared with the parent.
        try {
            DB::reconnect();
            runBackfill(BackfillThatSelfDestructs::class);
        } catch (Throwable) {
            // Falls through to the kill below either way.
        }

        // Never return into the test runner.
        posix_kill(getmypid(), SIGKILL);
    }

    pcntl_waitpid($pid, $status);

    expect(pcntl_wifsignaled($status))->toBeTrue()
        ->and(pcntl_wtermsig($status))->toBe(SIGKILL);

    DB::reconnect();

    // 4. Inspect the wreckage. The run still claims to be running — nothing
    // got the chance to mark it otherwise — and the cursor sits at the end of
    // the last batch that actually committed.
    $crashed = BackfillRun::latest('id')->first();

    expect($crashed->status)->toBe(RunStatus::Running)
        ->and($crashed->cursor)->toBe($endOfFirstBatch)
        ->and($crashed->processed_count)->toBe(2);

    // The batch that was in flight rolled back whole: no half-done batch.
    expect(User::whereNotNull('slug')->count())->toBe(2)
        ->and(DB::table('backfill_locks')->where('backfill', $crashed->backfill)->exists())->toBeTrue();

    // 5. Resume. The cold heartbeat is what marks the run interrupted and
    // frees the abandoned lock.
    BackfillThatSelfDestructs::$killOnBatch = 0;
    config()->set('backfill.stale_after', 0);
    sleep(1);

    $resumed = runBackfill(BackfillThatSelfDestructs::class);

    expect($resumed->id)->toBe($crashed->id)
        ->and($resumed->status)->toBe(RunStatus::Completed)
        ->and($resumed->processed_count)->toBe(9);

    // 6. The end state is byte-for-byte what the uninterrupted run produced.
    expect(userStateByName())->toBe($expected);

    // Every row processed exactly once — the rolled-back batch was redone, not
    // double-applied.
    expect(User::where('process_count', 1)->count())->toBe(9)
        ->and(User::where('process_count', '!=', 1)->count())->toBe(0);
});

it('leaves no work half-applied when killed inside a batch', function () {
    User::seedUnslugged(6);

    BackfillThatSelfDestructs::$killOnBatch = 1;

    $pid = pcntl_fork();

    if ($pid === -1) {
        $this->markTestSkipped('Could not fork.');
    }

    if ($pid === 0) {
        try {
            DB::reconnect();
            runBackfill(BackfillThatSelfDestructs::class);
        } catch (Throwable) {
        }

        posix_kill(getmypid(), SIGKILL);
    }

    pcntl_waitpid($pid, $status);

    expect(pcntl_wtermsig($status))->toBe(SIGKILL);

    DB::reconnect();

    // Killed during the very first batch, so nothing committed at all.
    $crashed = BackfillRun::latest('id')->first();

    expect($crashed->cursor)->toBeNull()
        ->and($crashed->processed_count)->toBe(0)
        ->and(User::whereNotNull('slug')->count())->toBe(0);

    BackfillThatSelfDestructs::$killOnBatch = 0;
    config()->set('backfill.stale_after', 0);
    sleep(1);

    $resumed = runBackfill(BackfillThatSelfDestructs::class);

    expect($resumed->status)->toBe(RunStatus::Completed)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});
