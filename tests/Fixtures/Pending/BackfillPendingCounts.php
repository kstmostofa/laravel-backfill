<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Pending;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

class BackfillPendingCounts extends Backfill
{
    public int $batchSize = 2;

    public function collection(): Builder
    {
        return User::query()->where('process_count', 0);
    }

    public function process($record): void
    {
        $record->forceFill(['process_count' => 1])->save();
    }
}
