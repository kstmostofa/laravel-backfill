<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\FailedRowRetrier;

class RetryFailedBackfillCommand extends Command
{
    protected $signature = 'backfill:retry-failed
        {name : The backfill whose failed rows should be retried}
        {--run= : Retry a specific run instead of the most recent one}
        {--limit= : Retry at most this many rows}';

    protected $description = 'Re-process only the rows a run recorded as failed';

    public function handle(BackfillRegistry $registry, FailedRowRetrier $retrier): int
    {
        try {
            $backfill = $registry->find($this->argument('name'));
        } catch (BackfillNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $this->option('run')
            ? BackfillRun::find((int) $this->option('run'))
            : BackfillRun::query()->where('backfill', $backfill->name())->latest('id')->first();

        if (! $run) {
            $this->components->error("No run found for [{$backfill->name()}].");

            return self::FAILURE;
        }

        if ($run->failed_count === 0) {
            $this->components->info("Run #{$run->id} has no failed rows to retry.");

            return self::SUCCESS;
        }

        try {
            $result = $retrier->retry($backfill, $run, $this->intOption('limit'));
        } catch (BackfillAlreadyRunning $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['retried'] === 0) {
            $this->components->info("Run #{$run->id} has no unresolved errors left.");

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Retried %d rows from run #%d: %d now succeeded, %d still failing.',
            $result['retried'],
            $run->id,
            $result['resolved'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function intOption(string $key): ?int
    {
        $value = $this->option($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
