<?php

use Illuminate\Support\Facades\Queue;
use Kstmostofa\Backfill\Dashboard\BackfillDashboard;
use Kstmostofa\Backfill\Dashboard\Dashboard;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    BackfillWithFailingRow::reset();
    Dashboard::forgetAuth();
    config()->set('backfill.dashboard.enabled', true);
});

afterEach(fn () => Dashboard::forgetAuth());

it('lists every discovered backfill', function () {
    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Livewire::test(BackfillDashboard::class)
        ->assertSee('user-slugs')
        ->assertSee('Completed');
});

it('says so when a backfill has never run', function () {
    Livewire::test(BackfillDashboard::class)->assertSee('never run');
});

it('queues a run rather than doing it in the web request', function () {
    Queue::fake();
    User::seedUnslugged(6);

    Livewire::test(BackfillDashboard::class)
        ->call('start', 'user-slugs')
        ->assertSee('Queued');

    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->backfill === 'user-slugs');

    // A backfill takes hours; an HTTP worker does not.
    expect(User::whereNull('slug')->count())->toBe(6);
});

/**
 * The row ceiling used to make a backfill unstartable from the dashboard
 * altogether: the button dispatched a job with force disabled, the guard threw
 * inside the worker, and the page carried on saying "never run" while
 * failed_jobs filled up out of sight.
 */
it('asks before starting a run the guards would refuse', function () {
    Queue::fake();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 5);

    Livewire::test(BackfillDashboard::class)
        ->call('start', 'user-slugs')
        ->assertSet('confirming', 'user-slugs')
        ->assertSee('above the 5 row ceiling')
        ->assertSee('Run anyway');

    // Nothing was queued, so nothing fails in a worker later.
    Queue::assertNothingPushed();
});

it('starts the run once someone confirms it', function () {
    Queue::fake();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 5);

    Livewire::test(BackfillDashboard::class)
        ->call('start', 'user-slugs')
        ->call('runAnyway')
        ->assertSee('guards overridden')
        ->assertSet('confirming', null);

    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->backfill === 'user-slugs'
        && $job->force === true);
});

it('lets the confirmation be dismissed', function () {
    Queue::fake();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 5);

    Livewire::test(BackfillDashboard::class)
        ->call('start', 'user-slugs')
        ->call('cancelConfirmation')
        ->assertSet('confirming', null)
        ->assertDontSee('Run anyway');

    Queue::assertNothingPushed();
});

it('asks before resuming into a refusal too', function () {
    Queue::fake();
    User::seedUnslugged(10);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
    config()->set('backfill.guards.max_rows_without_confirmation', 2);

    Livewire::test(BackfillDashboard::class)
        ->call('resume', 'user-slugs')
        ->assertSet('confirming', 'user-slugs');

    Queue::assertNothingPushed();
});

it('queues straight away when nothing objects', function () {
    Queue::fake();
    User::seedUnslugged(10);
    config()->set('backfill.guards.max_rows_without_confirmation', 1000);

    Livewire::test(BackfillDashboard::class)
        ->call('start', 'user-slugs')
        ->assertSet('confirming', null)
        ->assertSee('Queued');

    Queue::assertPushed(RunBackfillJob::class, fn ($job) => $job->force === false);
});

it('pauses a running backfill', function () {
    User::seedUnslugged(6);
    $run = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
    $run->forceFill(['status' => RunStatus::Running])->save();

    Livewire::test(BackfillDashboard::class)->call('pause', 'user-slugs');

    expect($run->fresh()->status)->toBe(RunStatus::Paused);
});

it('refuses to pause something that is not running', function () {
    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Livewire::test(BackfillDashboard::class)
        ->call('pause', 'user-slugs')
        ->assertSee('is not running');
});

it('cancels a run', function () {
    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    Livewire::test(BackfillDashboard::class)->call('cancel', 'user-slugs');

    expect(BackfillRun::latest('id')->first()->status)->toBe(RunStatus::Cancelled);
});

it('shows the detail panel for a selected run', function () {
    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 2]);

    Livewire::test(BackfillDashboard::class)
        ->call('select', 'user-slugs')
        ->assertSee('Throughput')
        ->assertSee('Cursor')
        ->assertSee('Batches');
});

it('lists failed rows and retries them', function () {
    User::seedUnslugged(6);
    BackfillWithFailingRow::$poisoned = [3];

    runBackfill(BackfillWithFailingRow::class);

    $component = Livewire::test(BackfillDashboard::class)
        ->call('select', 'with-failing-row')
        ->assertSee('Failed rows')
        ->assertSee('Row 3 is poisoned');

    BackfillWithFailingRow::$poisoned = [];

    $component->call('retryFailed', 'with-failing-row')
        ->assertSee('1 succeeded');

    expect(User::whereNull('slug')->count())->toBe(0);
});

it('reports an unknown backfill instead of blowing up', function () {
    Livewire::test(BackfillDashboard::class)
        ->call('start', 'no-such-backfill')
        ->assertSee('No backfill named');
});

it('builds a sparkline scaled to the slowest batch', function () {
    config()->set('backfill.record_batches', true);
    User::seedUnslugged(6);

    $run = runBackfill(BackfillUserSlugs::class);

    // Six rows finish in well under a millisecond, so stamp known durations on
    // the recorded batches to check the scaling rather than the clock.
    \Kstmostofa\Backfill\Models\BackfillRunBatch::where('run_id', $run->id)
        ->orderBy('id')
        ->get()
        ->each(fn ($batch, $i) => $batch->forceFill(['duration_ms' => [10, 40, 20][$i]])->save());

    $sparkline = Livewire::test(BackfillDashboard::class)
        ->call('select', 'user-slugs')
        ->instance()
        ->sparkline;

    expect($sparkline)->toHaveCount(3)
        ->and(array_column($sparkline, 'height'))->toBe([25.0, 100.0, 50.0])
        ->and(array_column($sparkline, 'ms'))->toBe([10, 40, 20]);
});

it('explains an empty sparkline rather than showing nothing', function () {
    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    Livewire::test(BackfillDashboard::class)
        ->call('select', 'user-slugs')
        ->assertSee('No batch timings recorded');
});

it('denies access by default outside local', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(Dashboard::check(request()))->toBeFalse();
});

it('allows access in local development', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(Dashboard::check(request()))->toBeTrue();
});

it('honours a custom authorisation callback', function () {
    app()->detectEnvironment(fn () => 'production');

    Dashboard::auth(fn () => true);
    expect(Dashboard::check(request()))->toBeTrue();

    Dashboard::auth(fn () => false);
    expect(Dashboard::check(request()))->toBeFalse();
});

it('rejects an unauthorised request to the dashboard route', function () {
    app()->detectEnvironment(fn () => 'production');
    Dashboard::auth(fn () => false);

    $this->get('/backfills')->assertForbidden();
});

it('serves the dashboard to an authorised request', function () {
    Dashboard::auth(fn () => true);

    $this->get('/backfills')
        ->assertSuccessful()
        ->assertSee('Backfills');
});
