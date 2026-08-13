<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Models\BackfillRun;
use Throwable;

class RowFailed
{
    use Dispatchable;

    public function __construct(
        public readonly BackfillRun $run,
        /** @var mixed The row that failed — a model, or a stdClass on the fast path. */
        public readonly mixed $record,
        public readonly ?string $recordId,
        public readonly Throwable $exception,
    ) {}
}
