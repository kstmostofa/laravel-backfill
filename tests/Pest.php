<?php

use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use Kstmostofa\Backfill\Tests\ChaosTestCase;
use Kstmostofa\Backfill\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(ChaosTestCase::class)->in('Chaos');

/**
 * Run a backfill synchronously, the way a test wants it: no progress bar, no
 * command layer, just the runner.
 *
 * @param  class-string<Backfill>|Backfill  $backfill
 */
function runBackfill($backfill, array $options = []): BackfillRun
{
    $instance = is_string($backfill) ? new $backfill : $backfill;

    return app(BackfillRunner::class)->run($instance, new RunOptions(...$options));
}

/**
 * The end state of the fixture table, keyed by name so it survives a reseed.
 */
function userStateByName(): array
{
    return \Kstmostofa\Backfill\Tests\Fixtures\User::query()
        ->orderBy('name')
        ->get()
        ->mapWithKeys(fn ($user) => [$user->name => [
            'slug' => $user->slug,
            'process_count' => $user->process_count,
        ]])
        ->all();
}
