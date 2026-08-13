<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Models\BackfillRun;
use Throwable;

class BackfillFailed
{
    use Dispatchable;

    public function __construct(
        public readonly BackfillRun $run,
        public readonly Throwable $exception,
    ) {}
}
