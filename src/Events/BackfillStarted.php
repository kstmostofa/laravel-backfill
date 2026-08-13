<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Models\BackfillRun;

class BackfillStarted
{
    use Dispatchable;

    public function __construct(
        public readonly BackfillRun $run,
        /** True when this run is continuing from a committed cursor. */
        public readonly bool $resumed = false,
    ) {}
}
