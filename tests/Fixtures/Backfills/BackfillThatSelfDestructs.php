<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * SIGKILLs its own process part-way through a batch — an uncatchable, no
 * destructors, no shutdown handlers kill, exactly like the OOM killer or a
 * `kill -9` during a deploy. Used by the chaos test.
 *
 * The kill lands INSIDE the batch transaction, so the correct outcome is that
 * the batch is rolled back entirely and redone on resume.
 */
class BackfillThatSelfDestructs extends Backfill
{
    public int $batchSize = 2;

    /** Kill the process while processing this batch number (1-indexed). */
    public static int $killOnBatch = 2;

    protected int $batchesSeen = 0;

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function process($record): void
    {
        $record->forceFill([
            'slug' => Str::slug($record->name),
            'process_count' => $record->process_count + 1,
        ])->save();
    }

    public function afterBatch(Collection $rows, BackfillRun $run): void
    {
        $this->batchesSeen++;

        if ($this->batchesSeen === static::$killOnBatch) {
            posix_kill(getmypid(), SIGKILL);
        }
    }
}
