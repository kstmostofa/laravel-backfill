<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\Ledger;

class StatusBackfillCommand extends Command
{
    protected $signature = 'backfill:status {name : The backfill to inspect}';

    protected $description = 'Show progress, throughput and errors for a backfill';

    public function handle(BackfillRegistry $registry): int
    {
        try {
            $backfill = $registry->find($this->argument('name'));
        } catch (BackfillNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $run = BackfillRun::query()
            ->where('backfill', $backfill->name())
            ->latest('id')
            ->first();

        if (! $run) {
            $this->components->warn("Backfill [{$backfill->name()}] has never been run.");

            return self::SUCCESS;
        }

        $percent = $run->progressPercent();
        $throughput = $run->throughputPerSecond();

        $this->components->twoColumnDetail('<fg=gray>Run</>', "#{$run->id}");
        $this->components->twoColumnDetail('Status', $run->isStale() ? $run->status->label().' <fg=red>(stale heartbeat)</>' : $run->status->label());
        $this->components->twoColumnDetail('Progress', $percent === null
            ? number_format($run->processed_count).' processed'
            : sprintf('%s / %s (%s%%)', number_format($run->processed_count), number_format($run->total_estimate), $percent));
        $this->components->twoColumnDetail('Failed', number_format($run->failed_count));
        $this->components->twoColumnDetail('Batches', number_format($run->batch_count).' of '.number_format($run->batch_size));
        $this->components->twoColumnDetail('Cursor', $run->cursor ?? '—');
        $this->components->twoColumnDetail('Throughput', $throughput === null ? '—' : $throughput.' rows/sec');
        $this->components->twoColumnDetail('Started', $run->started_at?->diffForHumans() ?? '—');
        $this->components->twoColumnDetail('Heartbeat', $run->heartbeat_at?->diffForHumans() ?? '—');
        $this->components->twoColumnDetail('Started by', $run->started_by ?? '—');

        if ($run->tenant) {
            $this->components->twoColumnDetail('Tenant', $run->tenant);
        }

        if ($run->skipped_count > 0) {
            $this->components->twoColumnDetail('Skipped (ledger)', number_format($run->skipped_count));
        }

        if (! empty($run->meta['parameter_summary'])) {
            $this->components->twoColumnDetail('Parameters', $run->meta['parameter_summary']);
        }

        if (! empty($run->meta['stop_reason'])) {
            $this->newLine();
            $this->components->warn($run->meta['stop_reason']);
        }

        // Rows claimed but never confirmed: the process died between marking a
        // row and finishing it, so nobody knows whether the side effect escaped.
        if ($backfill->ledger) {
            $unconfirmed = app(Ledger::class)->unconfirmedCount($backfill->name());

            if ($unconfirmed > 0) {
                $this->newLine();
                $this->components->warn(sprintf(
                    '%s rows are claimed but unconfirmed — the run stopped part way through them, '
                    .'so whether the side effect happened is unknown. They will not be retried '
                    .'automatically.',
                    number_format($unconfirmed),
                ));
            }
        }

        if ($run->error) {
            $this->newLine();
            $this->components->error($run->error);
        }

        $errors = $run->errors()->latest('id')->limit(10)->get();

        if ($errors->isNotEmpty()) {
            $this->newLine();
            $this->components->info("Most recent failed rows ({$run->failed_count} total):");

            $this->table(
                ['Record', 'Exception', 'Message'],
                $errors->map(fn ($error) => [
                    $error->record_id ?? '—',
                    class_basename($error->exception_class),
                    str($error->message)->limit(60),
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
