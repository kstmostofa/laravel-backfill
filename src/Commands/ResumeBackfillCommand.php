<?php

namespace Kstmostofa\Backfill\Commands;

use Illuminate\Console\Command;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Runner\BackfillRunner;

class ResumeBackfillCommand extends Command
{
    protected $signature = 'backfill:resume
        {name : The backfill to resume}
        {--batch-size= : Rows per batch}
        {--sleep= : Milliseconds to sleep between batches}
        {--max-batches= : Stop cleanly after this many batches}
        {--force : Run without confirmation in production}';

    protected $description = 'Continue a paused or interrupted backfill from its last committed cursor';

    public function handle(BackfillRegistry $registry, BackfillRunner $runner): int
    {
        try {
            $backfill = $registry->find($this->argument('name'));
        } catch (BackfillNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $runner->resumableRun($backfill)) {
            $this->components->error(
                "Backfill [{$backfill->name()}] has no run to resume. Start one with `backfill:run {$backfill->name()}`."
            );

            return self::FAILURE;
        }

        return $this->call('backfill:run', array_filter([
            'name' => $this->argument('name'),
            '--batch-size' => $this->option('batch-size'),
            '--sleep' => $this->option('sleep'),
            '--max-batches' => $this->option('max-batches'),
            '--force' => $this->option('force'),
        ], fn ($value) => $value !== null && $value !== false && $value !== ''));
    }
}
