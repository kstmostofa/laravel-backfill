<?php

namespace Kstmostofa\Backfill\Runner;

use Closure;

class RunOptions
{
    public function __construct(
        public ?int $batchSize = null,
        public ?int $sleepMs = null,
        /** Start a new run instead of resuming the last resumable one. */
        public bool $fresh = false,
        /** Skip the COUNT() used for progress estimates. */
        public bool $withoutEstimate = false,
        /** Stop cleanly after this many batches. */
        public ?int $maxBatches = null,
        public ?string $startedBy = null,
        /** Called after every committed batch: fn (BackfillRun $run, int $batchSize) */
        public ?Closure $onBatch = null,
    ) {}
}
