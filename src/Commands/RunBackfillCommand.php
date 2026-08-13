<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;

class RunBackfillCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'backfill:run
        {name : The backfill to run}
        {--fresh : Ignore any resumable run and start from the beginning}
        {--batch-size= : Rows per batch}
        {--sleep= : Milliseconds to sleep between batches}
        {--max-batches= : Stop cleanly after this many batches}
        {--no-count : Skip the row count used for progress estimates}
        {--force : Run without confirmation in production}';

    protected $description = 'Run a backfill, resuming from its last committed cursor';

    public function handle(BackfillRegistry $registry, BackfillRunner $runner): int
    {
        try {
            $backfill = $registry->find($this->argument('name'));
        } catch (BackfillNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $resumable = $this->option('fresh') ? null : $runner->resumableRun($backfill);

        if ($resumable) {
            $this->components->info(sprintf(
                'Resuming run #%d (%s) from cursor %s — %s rows already processed.',
                $resumable->id,
                $resumable->status->value,
                $resumable->cursor ?? 'the beginning',
                number_format($resumable->processed_count),
            ));
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $bar = null;

        $options = new RunOptions(
            batchSize: $this->intOption('batch-size'),
            sleepMs: $this->intOption('sleep'),
            fresh: (bool) $this->option('fresh'),
            withoutEstimate: (bool) $this->option('no-count'),
            maxBatches: $this->intOption('max-batches'),
            startedBy: $this->startedBy(),
            onBatch: function (BackfillRun $run, int $count) use (&$bar) {
                if ($bar === null && $run->total_estimate) {
                    $bar = $this->output->createProgressBar($run->total_estimate);
                    $bar->start();
                }

                $bar?->advance($count);
            },
        );

        try {
            $run = $runner->run($backfill, $options);
        } catch (BackfillAlreadyRunning|BackfillRefused $e) {
            $bar?->finish();
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $bar?->finish();
        $this->newLine(2);

        return $this->report($run);
    }

    protected function report(BackfillRun $run): int
    {
        $summary = sprintf(
            '%s processed, %s failed, %s batches',
            number_format($run->processed_count),
            number_format($run->failed_count),
            number_format($run->batch_count),
        );

        return match ($run->status) {
            RunStatus::Completed => tap(self::SUCCESS, fn () => $this->components->info("Backfill [{$run->backfill}] completed: {$summary}.")),
            RunStatus::Paused => tap(self::SUCCESS, fn () => $this->components->warn(
                "Backfill [{$run->backfill}] paused at cursor {$run->cursor}: {$summary}. Resume with `backfill:resume {$run->backfill}`."
            )),
            RunStatus::Cancelled => tap(self::SUCCESS, fn () => $this->components->warn("Backfill [{$run->backfill}] cancelled: {$summary}.")),
            default => tap(self::FAILURE, fn () => $this->components->error("Backfill [{$run->backfill}] ended as {$run->status->value}: {$summary}.")),
        };
    }

    protected function intOption(string $key): ?int
    {
        $value = $this->option($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function startedBy(): string
    {
        return 'cli:'.(get_current_user() ?: 'unknown');
    }
}
