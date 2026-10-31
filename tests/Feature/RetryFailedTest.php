<?php

use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Runner\FailedRowRetrier;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillPerTenant;
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

function tenantRunWithOneFailure(string $tenant): BackfillRun
{
    $user = User::create(['name' => $tenant.'-1']);

    $run = BackfillRun::create([
        'backfill' => 'per-tenant',
        'tenant' => $tenant,
        'backfill_class' => BackfillPerTenant::class,
        'status' => RunStatus::Completed,
        'key_name' => 'id',
        'batch_size' => 2,
        'failed_count' => 1,
    ]);

    BackfillRunError::create([
        'run_id' => $run->id,
        'record_id' => (string) $user->id,
        'exception_class' => RuntimeException::class,
        'message' => 'boom',
    ]);

    return $run;
}

function holdLockElsewhere(string $key): void
{
    DB::table('backfill_locks')->insert([
        'backfill' => $key,
        'owner' => 'another-host:123',
        'acquired_at' => now(),
        'heartbeat_at' => now(),
    ]);
}

it('retries one tenant while another tenant is running', function () {
    BackfillPerTenant::reset();
    $run = tenantRunWithOneFailure('globex');
    holdLockElsewhere('per-tenant:acme');

    $result = app(FailedRowRetrier::class)
        ->retry(new BackfillPerTenant, $run);

    expect($result['resolved'])->toBe(1)
        ->and(BackfillPerTenant::$switchedTo)->toBe(['globex']);
});

it('will not retry a tenant whose run is still going', function () {
    $run = tenantRunWithOneFailure('globex');
    holdLockElsewhere('per-tenant:globex');

    expect(fn () => app(FailedRowRetrier::class)
        ->retry(new BackfillPerTenant, $run))
        ->toThrow(BackfillAlreadyRunning::class);
});
