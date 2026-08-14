<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
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
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
    );

    expect(User::whereNotNull('slug')->count())->toBe(2);

    Queue::assertPushed(RunBackfillJob::class, 1);
});

it('stops chaining once the backfill is finished', function () {
    Queue::fake();
    User::seedUnslugged(4);

    (new RunBackfillJob('user-slugs', batchesPerJob: 10, batchSize: 2))->handle(
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
    );

    expect(BackfillRun::latest('id')->first()->status)->toBe(RunStatus::Completed);

    Queue::assertNothingPushed();
});

it('does not chain past a pause it did not ask for', function () {
    Queue::fake();
    User::seedUnslugged(60);
    BackfillWithFailingRow::$poisoned = range(1, 60);

    (new RunBackfillJob('with-failing-row', batchesPerJob: 100, batchSize: 10))->handle(
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
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
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
    );

    // No exception, no work, no chained job — the other worker has it.
    expect(User::whereNull('slug')->count())->toBe(6);
    Queue::assertNothingPushed();
});

it('runs the whole backfill across a chain of jobs', function () {
    User::seedUnslugged(7);

    // Drive the chain by hand, the way a worker would.
    $registry = app(\Kstmostofa\Backfill\BackfillRegistry::class);
    $runner = app(\Kstmostofa\Backfill\Runner\BackfillRunner::class);

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
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
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
        app(\Kstmostofa\Backfill\BackfillRegistry::class),
        app(\Kstmostofa\Backfill\Runner\BackfillRunner::class),
    );

    expect(User::whereNull('slug')->count())->toBe(0);
});
