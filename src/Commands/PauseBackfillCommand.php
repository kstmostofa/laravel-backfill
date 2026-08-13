<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Models\BackfillRun;

class PauseBackfillCommand extends Command
{
    protected $signature = 'backfill:pause {name : The backfill to pause}';

    protected $description = 'Ask a running backfill to stop cleanly after its current batch';

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
            ->whereIn('status', [RunStatus::Running->value, RunStatus::Pending->value])
            ->latest('id')
            ->first();

        if (! $run) {
            $this->components->warn("Backfill [{$backfill->name()}] is not running.");

            return self::SUCCESS;
        }

        $run->forceFill(['status' => RunStatus::Paused])->save();

        // The runner re-reads status between batches, so it stops only once the
        // batch in flight has committed its cursor.
        $this->components->info(
            "Backfill [{$backfill->name()}] will pause after the current batch. "
            ."Resume with `backfill:resume {$backfill->name()}`."
        );

        return self::SUCCESS;
    }
}
