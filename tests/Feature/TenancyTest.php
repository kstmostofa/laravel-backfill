<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillPerTenant;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

beforeEach(function () {
    BackfillPerTenant::reset();

    foreach (['acme', 'acme', 'acme', 'globex', 'globex'] as $i => $tenant) {
        User::create(['name' => $tenant.'-'.$i]);
    }
});

function runAll(array $options = []): array
{
    return app(BackfillRunner::class)->runAll(new BackfillPerTenant, new RunOptions(...$options));
}

it('gives each tenant its own run and cursor', function () {
    $runs = runAll();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->tenant)->toBe('acme')
        ->and($runs[1]->tenant)->toBe('globex')
        ->and($runs[0]->processed_count)->toBe(3)
        ->and($runs[1]->processed_count)->toBe(2)
        ->and($runs[0]->cursor)->not->toBe($runs[1]->cursor);
});

it('switches tenant context before reading each cursor', function () {
    runAll();

    expect(BackfillPerTenant::$switchedTo)->toBe(['acme', 'globex']);
});

it('processes every tenant\'s rows exactly once', function () {
    runAll();

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(5);
});

it('resumes one tenant without rewinding the others', function () {
    // Stop acme after one batch; globex never starts.
    $first = runAll(['maxBatches' => 1]);

    expect($first[0]->status)->toBe(RunStatus::Paused)
        ->and($first[0]->processed_count)->toBe(2)
        ->and($first[1]->processed_count)->toBe(2);

    $second = runAll();

    // acme picks up its own cursor; globex has nothing left to do.
    expect($second[0]->id)->toBe($first[0]->id)
        ->and($second[0]->processed_count)->toBe(3)
        ->and($second[1]->id)->toBe($first[1]->id)
        ->and(User::where('process_count', 1)->count())->toBe(5);
});

it('keeps one tenant\'s failure away from another', function () {
    $runs = runAll();

    // Mark acme's run failed and confirm globex is untouched by a resume.
    $runs[0]->forceFill(['status' => RunStatus::Failed])->save();

    expect(app(BackfillRunner::class)->resumableRun(new BackfillPerTenant, 'acme'))->not->toBeNull()
        ->and(app(BackfillRunner::class)->resumableRun(new BackfillPerTenant, 'globex'))->toBeNull();
});

it('locks tenants independently so they could run side by side', function () {
    app(\Kstmostofa\Backfill\Runner\LockManager::class)->acquire('per-tenant:acme', 999);

    // globex is a different lock, so it is free to run.
    $run = app(BackfillRunner::class)->run(new BackfillPerTenant, new RunOptions(tenant: 'globex'));

    expect($run->processed_count)->toBe(2);

    expect(fn () => app(BackfillRunner::class)->run(new BackfillPerTenant, new RunOptions(tenant: 'acme')))
        ->toThrow(\Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning::class);
});

it('handles a tenant list that changes between runs', function () {
    runAll();

    BackfillPerTenant::$tenants = ['acme'];
    BackfillPerTenant::$switchedTo = [];

    $runs = runAll();

    expect($runs)->toHaveCount(1)
        ->and(BackfillPerTenant::$switchedTo)->toBe(['acme']);
});

it('leaves a single-tenant backfill alone', function () {
    User::seedUnslugged(4);

    $runs = app(BackfillRunner::class)->runAll(new BackfillUserSlugs);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->tenant)->toBeNull()
        ->and(BackfillRun::whereNotNull('tenant')->count())->toBe(0);
});
