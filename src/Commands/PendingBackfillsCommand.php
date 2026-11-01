<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\PendingBackfills;
use Throwable;

/**
 * Lists, runs or queues every backfill that still has work to do.
 *
 * Meant for a deploy script, the same way `migrate --force` is: ship a new
 * backfill class with the code that needs it, and the deploy picks it up.
 * Queue mode is the usual choice there, so the deploy does not wait hours for
 * the data to catch up.
 */
class PendingBackfillsCommand extends Command
{
    protected $signature = 'backfill:pending
        {--run : Run each pending backfill now, one after another}
        {--queue : Queue each pending backfill as a chain of short jobs}
        {--force : Skip the production guards and confirmation}';

    protected $description = 'List, run or queue the backfills that have not finished yet';

    public function handle(PendingBackfills $pending): int
    {
        if ($this->option('run') && $this->option('queue')) {
            $this->components->error('Pass --run or --queue, not both.');

            return self::FAILURE;
        }

        $backfills = $pending->all();

        if ($backfills->isEmpty()) {
            $this->components->info('Nothing pending. Every backfill has finished.');

            return self::SUCCESS;
        }

        if (! $this->option('run') && ! $this->option('queue')) {
            $this->list($backfills->all());

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($backfills as $backfill) {
            $this->components->twoColumnDetail(
                '<fg=yellow;options=bold>'.$backfill->name().'</>',
                $this->option('queue') ? 'queueing' : 'running',
            );

            try {
                $code = $this->call('backfill:run', array_filter([
                    'name' => $backfill->name(),
                    '--queue' => (bool) $this->option('queue'),
                    '--force' => (bool) $this->option('force'),
                ]));
            } catch (Throwable $e) {
                // The run itself already recorded the failure and fired its
                // event. Report it here and treat it like any other failure.
                $this->components->error($e->getMessage());
                $code = self::FAILURE;
            }

            if ($code === self::SUCCESS) {
                continue;
            }

            $failed++;

            // A backfill that failed usually means something is wrong that
            // the next one may depend on. Stop and let a person look.
            if ($this->option('run')) {
                $this->components->error("Stopped after [{$backfill->name()}] failed. The rest are still pending.");

                return self::FAILURE;
            }
        }

        if ($this->option('run')) {
            $left = $pending->all();

            if ($left->isNotEmpty()) {
                $this->components->warn(sprintf(
                    '%d still pending, most likely paused by a guard, the throttle or the circuit breaker: %s.',
                    $left->count(),
                    $left->map->name()->implode(', '),
                ));
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, Backfill>  $backfills
     */
    protected function list(array $backfills): void
    {
        $latest = BackfillRun::query()
            ->whereIn('backfill', array_map(fn (Backfill $backfill) => $backfill->name(), $backfills))
            ->orderByDesc('id')
            ->get()
            ->groupBy('backfill')
            ->map->first();

        $this->table(
            ['Name', 'Class', 'Last run', 'Processed', 'Cursor'],
            array_map(function (Backfill $backfill) use ($latest) {
                $run = $latest->get($backfill->name());

                return [
                    $backfill->name(),
                    class_basename($backfill),
                    $run ? $run->status->label() : 'Never run',
                    $run ? number_format($run->processed_count) : '—',
                    $run->cursor ?? '—',
                ];
            }, $backfills),
        );

        $this->newLine();
        $this->components->info('Run them with `backfill:pending --run`, or `--queue` from a deploy script.');
    }
}
