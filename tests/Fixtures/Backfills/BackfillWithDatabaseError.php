<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\Tag;

/**
 * Fails rows with a genuine database error — a unique-index violation — rather
 * than a PHP exception thrown before the query runs.
 *
 * The distinction matters entirely on PostgreSQL: a failed statement puts the
 * surrounding transaction into an aborted state where every later statement is
 * rejected until rollback. Without a per-row savepoint, the first bad row would
 * take the rest of the batch, the error records, and the cursor advance with
 * it. A PHP exception never triggers that, so a fixture that only throws would
 * pass whether or not the savepoints existed.
 */
class BackfillWithDatabaseError extends Backfill
{
    public int $batchSize = 4;

    public function collection(): Builder
    {
        return Tag::query()->whereNull('label');
    }

    public function process($record): void
    {
        // Every row wants the same label, but the column is unique — the first
        // row succeeds and the rest collide.
        $record->forceFill(['label' => 'duplicate'])->save();
    }
}
