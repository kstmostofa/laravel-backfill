<?php

use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Runner\LockManager;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

it('refuses to start a backfill that is already running', function () {
    User::seedUnslugged(4);

    app(LockManager::class)->acquire('user-slugs', 999);

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillAlreadyRunning::class);

    expect(User::whereNull('slug')->count())->toBe(4);
});

it('leaves no half-started run behind when it loses the race', function () {
    User::seedUnslugged(4);

    app(LockManager::class)->acquire('user-slugs', 999);

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillAlreadyRunning::class);

    // A stray pending run here would make the next resume claim it was
    // continuing something, when nothing ever started.
    expect(\Kstmostofa\Backfill\Models\BackfillRun::count())->toBe(0);
});

it('records which run owns the lock', function () {
    User::seedUnslugged(6);

    $owners = [];

    runBackfill(BackfillUserSlugs::class, [
        'onBatch' => function ($run) use (&$owners) {
            $owners[] = DB::table('backfill_locks')->where('backfill', 'user-slugs')->value('run_id');
        },
    ]);

    $runId = \Kstmostofa\Backfill\Models\BackfillRun::latest('id')->first()->id;

    expect(array_unique($owners))->toBe([$runId]);
});

it('names the process holding the lock', function () {
    app(LockManager::class)->acquire('user-slugs', 999);

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillAlreadyRunning::class, gethostname().':'.getmypid());
});

it('releases the lock when the run finishes', function () {
    User::seedUnslugged(2);

    runBackfill(BackfillUserSlugs::class);

    expect(app(LockManager::class)->isHeld('user-slugs'))->toBeFalse();
});

it('releases the lock even when the run blows up', function () {
    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            throw new RuntimeException('boom');
        }

        public function afterBatch(\Illuminate\Support\Collection $rows, \Kstmostofa\Backfill\Models\BackfillRun $run): void
        {
            throw new RuntimeException('boom');
        }
    };

    expect(fn () => runBackfill($backfill))->toThrow(RuntimeException::class);

    expect(app(LockManager::class)->isHeld('user-slugs'))->toBeFalse();
});

it('takes over a lock abandoned by a killed process', function () {
    User::seedUnslugged(4);

    app(LockManager::class)->acquire('user-slugs', 999);

    // A killed process leaves its lock row behind with a heartbeat that stops
    // advancing. Once it goes cold the lock is free to take.
    DB::table('backfill_locks')
        ->where('backfill', 'user-slugs')
        ->update(['heartbeat_at' => now()->subHour()]);

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->processed_count)->toBe(4)
        ->and(app(LockManager::class)->isHeld('user-slugs'))->toBeFalse();
});

it('keeps the lock heartbeat fresh while running', function () {
    User::seedUnslugged(6);

    $heartbeats = [];

    runBackfill(BackfillUserSlugs::class, [
        'onBatch' => function () use (&$heartbeats) {
            $heartbeats[] = DB::table('backfill_locks')->where('backfill', 'user-slugs')->value('heartbeat_at');
        },
    ]);

    expect($heartbeats)->toHaveCount(3)
        ->and(array_filter($heartbeats))->toHaveCount(3);
});
