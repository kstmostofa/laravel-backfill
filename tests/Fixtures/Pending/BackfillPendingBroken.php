<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Pending;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * Sorts before the others and fails the whole run: the query calls a function
 * that does not exist, which is a bug, not a bad row. (An unknown column would
 * not do: SQLite quietly reads an unknown quoted identifier as a string.)
 */
class BackfillPendingBroken extends Backfill
{
    public function collection(): Builder
    {
        return User::query()->whereRaw('no_such_function(id) = 1');
    }

    public function process($record): void
    {
        //
    }
}
