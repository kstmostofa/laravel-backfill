<?php

namespace Kstmostofa\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kstmostofa\Backfill\Enums\StopReason;
use Kstmostofa\Backfill\Models\BackfillRun;

class BackfillPaused
{
    use Dispatchable;

    public function __construct(
        public readonly BackfillRun $run,
        public readonly ?StopReason $reason = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * A pause nobody asked for — the circuit breaker or the throttle deciding
     * to stop. This is the one worth waking someone up for.
     */
    public function wasAutomatic(): bool
    {
        return in_array($this->reason, [StopReason::CircuitBreaker, StopReason::Throttle], true);
    }
}
