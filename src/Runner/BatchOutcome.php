<?php

namespace Kstmostofa\Backfill\Runner;

class BatchOutcome
{
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $failed = 0,
        public readonly int $attempts = 1,
        public readonly int $durationMs = 0,
        /** Rows the ledger says were already dealt with. */
        public readonly int $skipped = 0,
    ) {}

    public function withAttempts(int $attempts, int $durationMs): self
    {
        return new self($this->processed, $this->failed, $attempts, $durationMs, $this->skipped);
    }
}
