<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Models\BackfillRun;

class ListBackfillsCommand extends Command
{
    protected $signature = 'backfill:list';

    protected $description = 'List the discovered backfills and the status of their last run';

    public function handle(BackfillRegistry $registry): int
    {
        $backfills = $registry->all();

        if ($backfills->isEmpty()) {
            $this->components->warn('No backfills found in '.config('backfill.path').'.');

            return self::SUCCESS;
        }

        $latest = BackfillRun::query()
            ->whereIn('backfill', $backfills->map->name()->all())
            ->orderByDesc('id')
            ->get()
            ->groupBy('backfill')
            ->map->first();

        $this->table(
            ['Name', 'Class', 'Last run', 'Processed', 'Failed', 'Cursor'],
            $backfills->map(function (Backfill $backfill) use ($latest) {
                $run = $latest->get($backfill->name());

                return [
                    $backfill->name(),
                    class_basename($backfill),
                    $run ? $run->status->label() : '—',
                    $run ? number_format($run->processed_count) : '—',
                    $run ? number_format($run->failed_count) : '—',
                    $run?->cursor ?? '—',
                ];
            })->all()
        );

        return self::SUCCESS;
    }
}
