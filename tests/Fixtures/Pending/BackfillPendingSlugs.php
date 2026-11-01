<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Pending;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

class BackfillPendingSlugs extends Backfill
{
    public int $batchSize = 2;

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function process($record): void
    {
        $record->forceFill(['slug' => 'slug-'.$record->id])->save();
    }
}
