<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * The canonical shape: a self-excluding collection. Once a row has a slug it
 * no longer matches, so re-running can never double-apply.
 */
class BackfillUserSlugs extends Backfill
{
    public int $batchSize = 2;

    public int $sleepMs = 0;

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
}
