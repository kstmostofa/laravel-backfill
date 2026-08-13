<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\DryRun\DryRunner;
use Kstmostofa\Backfill\DryRun\DryRunReport;
use Kstmostofa\Backfill\DryRun\SampleDiff;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;
use Kstmostofa\Backfill\Runner\ThrottleDecision;

class RunBackfillCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'backfill:run
        {name : The backfill to run}
        {--dry-run : Report what would happen and write nothing}
        {--samples= : Rows to sample during a dry run}
        {--fresh : Ignore any resumable run and start from the beginning}
        {--batch-size= : Rows per batch}
        {--sleep= : Milliseconds to sleep between batches}
        {--max-batches= : Stop cleanly after this many batches}
        {--no-count : Skip the row count used for progress estimates}
        {--force : Skip the production guards and confirmation}';

    protected $description = 'Run a backfill, resuming from its last committed cursor';

    public function handle(BackfillRegistry $registry, BackfillRunner $runner, DryRunner $dryRunner): int
    {
        try {
            $backfill = $registry->find($this->argument('name'));
        } catch (BackfillNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($dryRunner, $backfill);
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
            force: (bool) $this->option('force'),
            onBatch: function (BackfillRun $run, int $count) use (&$bar) {
                if ($bar === null && $run->total_estimate) {
                    $bar = $this->output->createProgressBar($run->total_estimate);
                    $bar->start();
                }

                $bar?->advance($count);
            },
            onThrottle: function (ThrottleDecision $decision) use (&$bar) {
                $bar?->clear();
                $this->components->warn($decision->reason);
                $bar?->display();
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

    protected function dryRun(DryRunner $dryRunner, Backfill $backfill): int
    {
        try {
            $report = $dryRunner->perform($backfill, $this->intOption('samples'));
        } catch (BackfillRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printDryRun($report);

        return self::SUCCESS;
    }

    protected function printDryRun(DryRunReport $report): void
    {
        $this->newLine();
        $this->components->info("Dry run: {$report->backfill} — nothing was written.");
        $this->newLine();

        $this->components->twoColumnDetail('Rows matching', $report->scope === null ? 'unknown' : number_format($report->scope));
        $this->components->twoColumnDetail('Batch size', number_format($report->batchSize));
        $this->components->twoColumnDetail(
            'Index',
            $report->plan->usesIndex === false
                ? '<fg=red>'.$report->plan->label().'</> — '.$report->plan->detail
                : $report->plan->label().' — '.$report->plan->detail,
        );
        $this->components->twoColumnDetail('Estimated duration', $report->estimatedDuration() ?? 'unknown');

        $this->newLine();

        if ($report->samples === []) {
            $this->components->warn('No rows matched, so there was nothing to sample.');

            return;
        }

        $this->components->info(sprintf('Sampled %d rows, rolled back:', count($report->samples)));

        $this->table(
            ['Row', 'What would change'],
            collect($report->samples)->map(fn (SampleDiff $diff) => [
                $diff->id,
                $diff->error !== null ? '<fg=red>'.$diff->summary().'</>' : $diff->summary(),
            ])->all()
        );

        if ($report->wouldChangeNothing()) {
            $this->components->warn(
                'None of the sampled rows changed. Either the work is already done, '
                .'or process() is not doing what you expect.'
            );
        }

        if ($report->failingSamples() > 0) {
            $this->components->error(sprintf(
                '%d of %d sampled rows would fail.',
                $report->failingSamples(),
                count($report->samples),
            ));
        }

        if ($report->hasSideEffects()) {
            $this->newLine();
            $this->components->warn('Side effects intercepted (these would have escaped in a real run):');

            foreach ($report->sideEffects as $kind => $count) {
                $perRow = $count === null ? null : $count / max(1, count($report->samples));

                $this->components->twoColumnDetail(
                    $kind,
                    $count === null
                        ? 'recorded (count unavailable)'
                        : sprintf(
                            '%d across %d rows%s',
                            $count,
                            count($report->samples),
                            $report->scope === null || $perRow === null
                                ? ''
                                : ' — roughly '.number_format((int) round($perRow * $report->scope)).' in full',
                        ),
                );
            }
        }

        if ($report->events !== []) {
            $this->newLine();
            $this->components->info('Application events fired (not suppressed, so observers still ran):');

            foreach ($report->events as $event => $count) {
                $this->components->twoColumnDetail(class_basename($event), (string) $count);
            }
        }
    }

    protected function report(BackfillRun $run): int
    {
        $summary = sprintf(
            '%s processed, %s failed, %s batches',
            number_format($run->processed_count),
            number_format($run->failed_count),
            number_format($run->batch_count),
        );

        $reason = $run->meta['stop_reason'] ?? null;

        return match ($run->status) {
            RunStatus::Completed => tap(self::SUCCESS, fn () => $this->components->info("Backfill [{$run->backfill}] completed: {$summary}.")),
            RunStatus::Paused => tap(self::SUCCESS, function () use ($run, $summary, $reason) {
                $this->components->warn(
                    "Backfill [{$run->backfill}] paused at cursor {$run->cursor}: {$summary}. "
                    ."Resume with `backfill:resume {$run->backfill}`."
                );

                if ($reason) {
                    $this->components->warn($reason);
                }
            }),
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
