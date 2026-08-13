<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

it('stops cleanly at a batch boundary and resumes where it left off', function () {
    User::seedUnslugged(6);

    $first = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 2]);

    expect($first->status)->toBe(RunStatus::Paused)
        ->and($first->processed_count)->toBe(4)
        ->and($first->cursor)->toBe('4')
        ->and(User::whereNull('slug')->count())->toBe(2);

    $second = runBackfill(BackfillUserSlugs::class);

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(RunStatus::Completed)
        ->and($second->processed_count)->toBe(6)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('picks up an interrupted run rather than starting over', function () {
    User::seedUnslugged(6);

    $first = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    // Recreate the state a hard kill leaves behind: still marked running,
    // heartbeat gone cold.
    $first->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
    ])->save();

    $resumable = app(BackfillRunner::class)->resumableRun(new BackfillUserSlugs);

    expect($resumable)->not->toBeNull()
        ->and($resumable->status)->toBe(RunStatus::Interrupted)
        ->and($resumable->cursor)->toBe('2');

    $second = runBackfill(BackfillUserSlugs::class);

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(RunStatus::Completed)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('starts a new run when asked for a fresh one', function () {
    User::seedUnslugged(4);

    $first = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
    $second = runBackfill(BackfillUserSlugs::class, ['fresh' => true]);

    expect($second->id)->not->toBe($first->id)
        ->and($second->cursor)->not->toBe($first->cursor)
        ->and(BackfillRun::count())->toBe(2);
});

it('does not resume a completed run', function () {
    User::seedUnslugged(2);

    runBackfill(BackfillUserSlugs::class);

    expect(app(BackfillRunner::class)->resumableRun(new BackfillUserSlugs))->toBeNull();
});

it('does not resume a cancelled run', function () {
    User::seedUnslugged(6);

    $run = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
    $run->forceFill(['status' => RunStatus::Cancelled])->save();

    expect(app(BackfillRunner::class)->resumableRun(new BackfillUserSlugs))->toBeNull();
});

it('leaves a failed run resumable from its last committed cursor', function () {
    User::seedUnslugged(6);

    $backfill = new class extends BackfillUserSlugs
    {
        public int $batchSize = 2;

        public function afterBatch(\Illuminate\Support\Collection $rows, BackfillRun $run): void
        {
            if ($run->batch_count >= 1) {
                throw new RuntimeException('database went away');
            }
        }
    };

    expect(fn () => runBackfill($backfill))->toThrow(RuntimeException::class, 'database went away');

    $run = BackfillRun::latest('id')->first();

    // The batch that threw rolled back whole — cursor and data agree.
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->cursor)->toBe('2')
        ->and($run->error)->toContain('database went away')
        ->and(User::whereNotNull('slug')->count())->toBe(2);

    $resumed = runBackfill(BackfillUserSlugs::class);

    expect($resumed->id)->toBe($run->id)
        ->and($resumed->status)->toBe(RunStatus::Completed)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});
