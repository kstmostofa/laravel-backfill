<?php

namespace Kstmostofa\Backfill\Testing;

use Closure;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Stands in for the runner in tests of code that starts backfills: a deploy
 * command, a scheduled task, a controller. Every run is recorded and nothing
 * touches the data, so a test can assert what would have run without seeding
 * the tables each backfill reads.
 *
 * To test what a backfill does to the data, run it for real with
 * InteractsWithBackfills::runBackfill() instead.
 */
class BackfillRunnerFake extends BackfillRunner
{
    /** @var array<int, array{backfill: Backfill, options: RunOptions}> */
    protected array $recorded = [];

    public function run(Backfill $backfill, ?RunOptions $options = null): BackfillRun
    {
        $options ??= new RunOptions;

        // Recorded with the parameters and tenant applied, so assertions see
        // the backfill the way the real runner would have.
        if ($options->tenant !== null) {
            $backfill->useTenant($options->tenant);
        }

        $backfill->withParameters($options->parameters);

        $this->recorded[] = ['backfill' => $backfill, 'options' => $options];

        // An unsaved run, so callers that report on the result still work.
        return (new BackfillRun)->forceFill([
            'backfill' => $backfill->name(),
            'tenant' => $options->tenant,
            'backfill_class' => $backfill::class,
            'status' => RunStatus::Completed,
            'key_name' => 'id',
            'processed_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'batch_count' => 0,
            'batch_size' => $options->batchSize ?? $backfill->resolvedBatchSize(),
            'sleep_ms' => $options->sleepMs ?? $backfill->resolvedSleepMs(),
            'started_by' => $options->startedBy,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Assert a backfill was run, optionally checking the run with a callback
     * that receives the backfill and its run options.
     *
     * @param  string  $backfill  Short name, class basename or class name.
     * @param  (Closure(Backfill, RunOptions): bool)|null  $callback
     */
    public function assertRan(string $backfill, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->ran($backfill, $callback),
            "The expected backfill [{$backfill}] was not run.",
        );
    }

    public function assertRanTimes(string $backfill, int $times): void
    {
        $count = count($this->ran($backfill));

        PHPUnit::assertSame(
            $times,
            $count,
            "The backfill [{$backfill}] was run {$count} times instead of {$times}.",
        );
    }

    /**
     * @param  (Closure(Backfill, RunOptions): bool)|null  $callback
     */
    public function assertNotRan(string $backfill, ?Closure $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->ran($backfill, $callback),
            "The unexpected backfill [{$backfill}] was run.",
        );
    }

    public function assertNothingRan(): void
    {
        $names = array_map(fn (array $run) => $run['backfill']->name(), $this->recorded);

        PHPUnit::assertEmpty($names, 'Backfills were run unexpectedly: '.implode(', ', $names).'.');
    }

    /**
     * The recorded runs of a backfill, optionally filtered by a callback.
     *
     * @param  (Closure(Backfill, RunOptions): bool)|null  $callback
     * @return array<int, array{backfill: Backfill, options: RunOptions}>
     */
    public function ran(string $backfill, ?Closure $callback = null): array
    {
        $needle = ltrim($backfill, '\\');

        return array_values(array_filter($this->recorded, function (array $run) use ($needle, $callback) {
            $instance = $run['backfill'];

            $matches = $instance->name() === $needle
                || $instance::class === $needle
                || class_basename($instance) === $needle;

            return $matches && ($callback === null || $callback($instance, $run['options']));
        }));
    }
}
