<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * The fast path: no model hydration, one UPDATE per batch.
 */
class BackfillWithoutHydration extends Backfill
{
    public int $batchSize = 2;

    public bool $hydrateModels = false;

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function processBatch(Collection $rows): void
    {
        DB::table('bf_users')
            ->whereIn('id', $rows->pluck('id'))
            ->update([
                'slug' => 'bulk',
                'process_count' => DB::raw('process_count + 1'),
            ]);
    }
}
