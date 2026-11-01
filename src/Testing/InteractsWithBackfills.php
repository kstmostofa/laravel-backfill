<?php

namespace Kstmostofa\Backfill\Testing;

use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithBackfills
{
    /**
     * Run a backfill synchronously and hand back its run record.
     *
     * Pass a small batch size — the default here is 2 — so tests exercise the
     * real pagination path instead of a single batch that would hide ordering
     * and cursor bugs.
     *
     * @param  class-string<Backfill>|Backfill  $backfill
     */
    protected function runBackfill($backfill, array $options = []): BackfillRun
    {
        $instance = is_string($backfill) ? new $backfill : $backfill;

        $options = array_merge(['batchSize' => 2], $options);

        return app(BackfillRunner::class)->run($instance, new RunOptions(...$options));
    }

    protected function assertBackfillCompleted(BackfillRun $run): void
    {
        $run->refresh();

        PHPUnit::assertSame(
            RunStatus::Completed,
            $run->status,
            sprintf(
                'Backfill [%s] ended as %s, not completed.%s',
                $run->backfill,
                $run->status->value,
                $run->error ? ' Error: '.$run->error : (isset($run->meta['stop_reason']) ? ' '.$run->meta['stop_reason'] : ''),
            ),
        );
    }

    protected function assertBackfillProcessed(BackfillRun $run, int $count): void
    {
        $run->refresh();

        PHPUnit::assertSame(
            $count,
            $run->processed_count,
            "Backfill [{$run->backfill}] processed {$run->processed_count} rows, not {$count}.",
        );
    }

    protected function assertBackfillHasNoFailures(BackfillRun $run): void
    {
        $run->refresh();

        $first = $run->errors()->oldest('id')->first();

        PHPUnit::assertSame(
            0,
            $run->failed_count,
            $first === null
                ? "Backfill [{$run->backfill}] recorded {$run->failed_count} failed rows."
                : sprintf(
                    'Backfill [%s] recorded %d failed rows. The first, row %s, threw %s: %s',
                    $run->backfill,
                    $run->failed_count,
                    $first->record_id ?? 'unknown',
                    class_basename($first->exception_class),
                    $first->message,
                ),
        );
    }
}
