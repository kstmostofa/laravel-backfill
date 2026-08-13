<?php

namespace Kstmostofa\Backfill\Testing;

use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;

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
}
