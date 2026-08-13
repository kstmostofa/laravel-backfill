<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\FakeDeadlockException;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use RuntimeException;

class BackfillThatDeadlocks extends Backfill
{
    public int $batchSize = 2;

    /** How many more times process() should deadlock before behaving. */
    public static int $deadlocksLeft = 0;

    /** Throw a non-transient error from afterBatch this many more times. */
    public static int $bugsLeft = 0;

    /** Counts every afterBatch call, so a test can prove a batch was not retried. */
    public static int $afterBatchCalls = 0;

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function process($record): void
    {
        if (static::$deadlocksLeft > 0) {
            static::$deadlocksLeft--;

            throw new FakeDeadlockException;
        }

        $record->forceFill([
            'slug' => Str::slug($record->name),
            'process_count' => $record->process_count + 1,
        ])->save();
    }

    public function afterBatch(Collection $rows, BackfillRun $run): void
    {
        static::$afterBatchCalls++;

        if (static::$bugsLeft > 0) {
            static::$bugsLeft--;

            throw new RuntimeException('column does not exist');
        }
    }

    public static function reset(): void
    {
        static::$deadlocksLeft = 0;
        static::$bugsLeft = 0;
        static::$afterBatchCalls = 0;
    }
}
