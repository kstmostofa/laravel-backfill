<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Models\BackfillRun;

class CancelBackfillCommand extends Command
{
    protected $signature = 'backfill:cancel {name : The backfill to cancel}';

    protected $description = 'Stop a backfill and mark it cancelled so it will not resume';

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
            ->whereIn('status', [
                RunStatus::Running->value,
                RunStatus::Pending->value,
                RunStatus::Paused->value,
                RunStatus::Interrupted->value,
                RunStatus::Failed->value,
            ])
            ->latest('id')
            ->first();

        if (! $run) {
            $this->components->warn("Backfill [{$backfill->name()}] has nothing to cancel.");

            return self::SUCCESS;
        }

        $run->forceFill([
            'status' => RunStatus::Cancelled,
            'finished_at' => $run->status === RunStatus::Running ? null : now(),
        ])->save();

        $this->components->info(
            "Backfill [{$backfill->name()}] cancelled at cursor {$run->cursor}. "
            .'Work already committed is kept; start again with `--fresh` to run from the beginning.'
        );

        return self::SUCCESS;
    }
}
