<?php

use Illuminate\Support\Facades\Queue;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\PendingBackfills;
use Kstmostofa\Backfill\Runner\RunOptions;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillOrderRefunds;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillPerTenant;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingBroken;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingCounts;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingManual;
use Kstmostofa\Backfill\Tests\Fixtures\Pending\BackfillPendingSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * Point discovery at nothing and register exactly these, so the tests are not
 * at the mercy of every fixture in the discovery folder.
 *
 * @param  array<int, class-string>  $classes
 */
function onlyBackfills(array $classes): void
{
    config()->set('backfill.path', null);

    $registry = app(BackfillRegistry::class)->flush();

    foreach ($classes as $class) {
        $registry->register($class);
    }
}

function pendingNames(): array
{
    return app(PendingBackfills::class)->all()->map->name()->all();
}

beforeEach(fn () => BackfillPerTenant::reset());

it('counts a backfill that never ran as pending', function () {
    onlyBackfills([BackfillPendingSlugs::class]);

    expect(pendingNames())->toBe(['pending-slugs']);
});

it('stops counting a backfill once it completes', function () {
    onlyBackfills([BackfillPendingSlugs::class]);
    User::seedUnslugged(3);

    runBackfill(BackfillPendingSlugs::class);

    expect(pendingNames())->toBe([]);
});

it('keeps a paused run pending so it gets resumed', function () {
    onlyBackfills([BackfillPendingSlugs::class]);
    User::seedUnslugged(6);

    runBackfill(BackfillPendingSlugs::class, ['maxBatches' => 1]);

    expect(pendingNames())->toBe(['pending-slugs']);
});

it('treats a cancelled run as finished', function () {
    onlyBackfills([BackfillPendingSlugs::class]);
    User::seedUnslugged(6);

    $run = runBackfill(BackfillPendingSlugs::class, ['maxBatches' => 1]);
    $run->forceFill(['status' => RunStatus::Cancelled])->save();

    expect(pendingNames())->toBe([]);
});

it('leaves out manual, operator and parameterised backfills', function () {
    onlyBackfills([BackfillPendingManual::class, BackfillOrderRefunds::class, BackfillPendingSlugs::class]);

    expect(pendingNames())->toBe(['pending-slugs']);
});

it('keeps a tenant backfill pending until every tenant has finished', function () {
    onlyBackfills([BackfillPerTenant::class]);

    foreach (['acme-1', 'acme-2', 'globex-1'] as $name) {
        User::create(['name' => $name]);
    }

    app(BackfillRunner::class)->run(
        new BackfillPerTenant,
        new RunOptions(tenant: 'acme'),
    );

    expect(pendingNames())->toBe(['per-tenant']);

    app(BackfillRunner::class)->run(
        new BackfillPerTenant,
        new RunOptions(tenant: 'globex'),
    );

    expect(pendingNames())->toBe([]);
});

it('lists what is pending without running anything', function () {
    onlyBackfills([BackfillPendingSlugs::class]);
    User::seedUnslugged(2);

    $this->artisan('backfill:pending')
        ->expectsOutputToContain('Never run')
        ->expectsOutputToContain('backfill:pending --run')
        ->assertSuccessful();

    expect(BackfillRun::count())->toBe(0);
});

it('says so when nothing is pending', function () {
    onlyBackfills([]);

    $this->artisan('backfill:pending')
        ->expectsOutputToContain('Nothing pending')
        ->assertSuccessful();
});

it('runs every pending backfill in turn', function () {
    onlyBackfills([BackfillPendingSlugs::class, BackfillPendingCounts::class]);
    User::seedUnslugged(5);

    $this->artisan('backfill:pending', ['--run' => true, '--force' => true])->assertSuccessful();

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', 0)->count())->toBe(0)
        ->and(pendingNames())->toBe([]);
});

it('stops at the first backfill that fails', function () {
    onlyBackfills([BackfillPendingBroken::class, BackfillPendingSlugs::class]);
    User::seedUnslugged(3);

    $this->artisan('backfill:pending', ['--run' => true, '--force' => true])
        ->expectsOutputToContain('Stopped after [pending-broken] failed')
        ->assertFailed();

    // pending-slugs sorts after pending-broken, so it never started.
    expect(User::whereNull('slug')->count())->toBe(3)
        ->and(pendingNames())->toBe(['pending-broken', 'pending-slugs']);
});

it('queues every pending backfill', function () {
    Queue::fake();
    onlyBackfills([BackfillPendingSlugs::class, BackfillPendingCounts::class]);

    $this->artisan('backfill:pending', ['--queue' => true, '--force' => true])->assertSuccessful();

    Queue::assertPushed(RunBackfillJob::class, 2);
    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->backfill === 'pending-slugs');
    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->backfill === 'pending-counts');
});

it('refuses --run and --queue together', function () {
    onlyBackfills([BackfillPendingSlugs::class]);

    $this->artisan('backfill:pending', ['--run' => true, '--queue' => true])->assertFailed();
});
