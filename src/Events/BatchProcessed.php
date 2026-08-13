<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BatchOutcome;

/**
 * Fired after every committed batch. On a large run that is thousands of
 * events, so keep listeners cheap and do not queue them.
 */
class BatchProcessed
{
    use Dispatchable;

    public function __construct(
        public readonly BackfillRun $run,
        public readonly BatchOutcome $outcome,
    ) {}
}
