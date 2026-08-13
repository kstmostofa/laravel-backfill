<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

it('runs a backfill from the command line', function () {
    User::seedUnslugged(5);

    $this->artisan('backfill:run', ['name' => 'user-slugs', '--force' => true])
        ->assertSuccessful();

    expect(User::whereNull('slug')->count())->toBe(0);
});

it('fails loudly on an unknown backfill', function () {
    $this->artisan('backfill:run', ['name' => 'nope', '--force' => true])
        ->assertFailed();
});

it('lists backfills with their last run', function () {
    User::seedUnslugged(2);
    runBackfill(BackfillUserSlugs::class);

    $this->artisan('backfill:list')
        ->expectsOutputToContain('user-slugs')
        ->assertSuccessful();
});

it('reports status for a run', function () {
    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    $this->artisan('backfill:status', ['name' => 'user-slugs'])
        ->expectsOutputToContain('Completed')
        ->assertSuccessful();
});

it('says so when a backfill has never run', function () {
    $this->artisan('backfill:status', ['name' => 'user-slugs'])
        ->expectsOutputToContain('never been run')
        ->assertSuccessful();
});

it('pauses a running backfill', function () {
    User::seedUnslugged(6);
    $run = runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
    $run->forceFill(['status' => RunStatus::Running])->save();

    $this->artisan('backfill:pause', ['name' => 'user-slugs'])->assertSuccessful();

    expect($run->fresh()->status)->toBe(RunStatus::Paused);
});

it('resumes a paused backfill', function () {
    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    $this->artisan('backfill:resume', ['name' => 'user-slugs', '--force' => true])
        ->assertSuccessful();

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and(BackfillRun::count())->toBe(1);
});

it('refuses to resume when there is nothing to resume', function () {
    $this->artisan('backfill:resume', ['name' => 'user-slugs', '--force' => true])
        ->assertFailed();
});

it('cancels a backfill so it will not resume', function () {
    User::seedUnslugged(6);
    runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);

    $this->artisan('backfill:cancel', ['name' => 'user-slugs'])->assertSuccessful();

    expect(BackfillRun::latest('id')->first()->status)->toBe(RunStatus::Cancelled);

    // A cancelled run is not picked back up.
    $this->artisan('backfill:run', ['name' => 'user-slugs', '--force' => true])->assertSuccessful();

    expect(BackfillRun::count())->toBe(2);
});

it('dry-runs from the command line without writing', function () {
    User::seedUnslugged(8);

    $this->artisan('backfill:run', ['name' => 'user-slugs', '--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect(User::whereNull('slug')->count())->toBe(8)
        ->and(BackfillRun::count())->toBe(0);
});

it('shows the scope and sampled diffs in a dry run', function () {
    User::seedUnslugged(8);

    $this->artisan('backfill:run', ['name' => 'user-slugs', '--dry-run' => true, '--samples' => 2])
        ->expectsOutputToContain('Rows matching')
        ->expectsOutputToContain('user-1')
        ->assertSuccessful();
});

it('generates a backfill class', function () {
    $path = base_path('app/Backfills/BackfillOrderTotals.php');
    config()->set('backfill.path', base_path('app/Backfills'));

    $this->artisan('make:backfill', ['name' => 'BackfillOrderTotals'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))
        ->toContain('class BackfillOrderTotals extends Backfill')
        ->toContain('public function collection(): Builder');

    @unlink($path);
});
