<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Models\BackfillRun;

class BackfillCompleted
{
    use Dispatchable;

    public function __construct(public readonly BackfillRun $run) {}
}
