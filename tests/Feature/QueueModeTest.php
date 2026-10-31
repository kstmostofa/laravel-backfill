<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Enums\StopReason;
use Kstmostofa\Backfill\Events\BackfillFailed;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\LockManager;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(fn () => BackfillWithFailingRow::reset());

it('queues a job instead of running inline', function () {
    Queue::fake();
    User::seedUnslugged(6);

    $this->artisan('backfill:run', ['name' => 'user-slugs', '--queue' => true, '--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->backfill === 'user-slugs');

    // Nothing ran in the web/CLI process.
    expect(User::whereNull('slug')->count())->toBe(6);
});

it('chains another job while there is more to do', function () {
    Queue::fake();
    User::seedUnslugged(6);

    // One batch per job, three batches of work.
    (new RunBackfillJob('user-slugs', batchesPerJob: 1, batchSize: 2))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    expect(User::whereNotNull('slug')->count())->toBe(2);

    Queue::assertPushed(RunBackfillJob::class, 1);
});

it('stops chaining once the backfill is finished', function () {
    Queue::fake();
    User::seedUnslugged(4);

    (new RunBackfillJob('user-slugs', batchesPerJob: 10, batchSize: 2))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    expect(BackfillRun::latest('id')->first()->status)->toBe(RunStatus::Completed);

    Queue::assertNothingPushed();
});

it('does not chain past a pause it did not ask for', function () {
    Queue::fake();
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    (new RunBackfillJob('with-failing-row', batchesPerJob: 100, batchSize: 10))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    $run = BackfillRun::latest('id')->first();

    // The circuit breaker stopped it. Queuing another job would just trip it
    // again, so a human has to look first.
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->meta['stop_code'])->toBe('circuit_breaker');

    Queue::assertNothingPushed();
});

it('backs off quietly when another worker holds the lock', function () {
    Queue::fake();
    User::seedUnslugged(6);

    app(LockManager::class)->acquire('user-slugs', 999);

    (new RunBackfillJob('user-slugs', batchesPerJob: 1, batchSize: 2))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    // No exception, no work, no chained job — the other worker has it.
    expect(User::whereNull('slug')->count())->toBe(6);
    Queue::assertNothingPushed();
});

it('runs the whole backfill across a chain of jobs', function () {
    User::seedUnslugged(7);

    // Drive the chain by hand, the way a worker would.
    $registry = app(BackfillRegistry::class);
    $runner = app(BackfillRunner::class);

    for ($i = 0; $i < 10; $i++) {
        (new RunBackfillJob('user-slugs', batchesPerJob: 1, batchSize: 2))->handle($registry, $runner);

        if (BackfillRun::latest('id')->first()->status === RunStatus::Completed) {
            break;
        }
    }

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(7)
        ->and(BackfillRun::count())->toBe(1);
});

it('tags the job so it can be found in Horizon', function () {
    expect((new RunBackfillJob('user-slugs'))->tags())->toBe(['backfill', 'backfill:user-slugs']);
});

/**
 * Guards can change between dispatch and execution — the table grows past the
 * ceiling, or a freeze window opens. That is a policy decision, not a crash,
 * and it should not fill failed_jobs with stack traces that read like a bug.
 */
it('logs a refusal instead of failing the job', function () {
    Log::spy();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 5);

    // No exception escapes the job.
    (new RunBackfillJob('user-slugs', batchesPerJob: 1, batchSize: 2))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    expect(User::whereNull('slug')->count())->toBe(10)
        ->and(BackfillRun::count())->toBe(0);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message) => str_contains($message, 'Backfill refused')
            && str_contains($message, 'above the 5 row ceiling')
    );
});

it('runs when the job carries an override', function () {
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 5);

    (new RunBackfillJob('user-slugs', batchesPerJob: 10, batchSize: 5, force: true))->handle(
        app(BackfillRegistry::class),
        app(BackfillRunner::class),
    );

    expect(User::whereNull('slug')->count())->toBe(0);
});

it('ends a slice once its time limit has passed', function () {
    User::seedUnslugged(6);

    // A zero-second limit stops as soon as the first batch commits.
    $run = runBackfill(BackfillUserSlugs::class, ['batchSize' => 2, 'maxSeconds' => 0]);

    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->meta['stop_code'])->toBe('time_limit')
        ->and($run->processed_count)->toBe(2);
});

it('treats a time-limited slice as safe to chain', function () {
    expect(StopReason::TimeLimit->isAutomaticallyResumable())->toBeTrue();
});

it('takes its timeout from config', function () {
    config()->set('backfill.queue.timeout', 42);

    expect((new RunBackfillJob('user-slugs'))->timeout)->toBe(42)
        ->and((new RunBackfillJob('user-slugs'))->failOnTimeout)->toBeTrue();
});

it('marks the run interrupted and frees the lock when the worker gives up on it', function () {
    Event::fake([BackfillFailed::class]);

    $run = BackfillRun::create([
        'backfill' => 'user-slugs',
        'backfill_class' => BackfillUserSlugs::class,
        'status' => RunStatus::Running,
        'key_name' => 'id',
        'batch_size' => 2,
        'heartbeat_at' => now(),
    ]);

    $locks = app(LockManager::class);
    $locks->acquire('user-slugs', $run->id);

    (new RunBackfillJob('user-slugs'))->failed(new RuntimeException('Job has timed out.'));

    expect($run->fresh()->status)->toBe(RunStatus::Interrupted)
        ->and($run->fresh()->error)->toBe('Job has timed out.')
        ->and($locks->isHeld('user-slugs'))->toBeFalse();

    Event::assertDispatched(BackfillFailed::class);
});

it('leaves a lock alone when another process holds it', function () {
    $run = BackfillRun::create([
        'backfill' => 'user-slugs',
        'backfill_class' => BackfillUserSlugs::class,
        'status' => RunStatus::Running,
        'key_name' => 'id',
        'batch_size' => 2,
        'heartbeat_at' => now(),
    ]);

    DB::table('backfill_locks')->insert([
        'backfill' => 'user-slugs',
        'run_id' => $run->id,
        'owner' => 'another-host:123',
        'acquired_at' => now(),
        'heartbeat_at' => now(),
    ]);

    (new RunBackfillJob('user-slugs'))->failed(new RuntimeException('Job has timed out.'));

    expect(app(LockManager::class)->isHeld('user-slugs'))->toBeTrue();
});
