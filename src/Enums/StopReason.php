<?php

namespace Kstmostofa\Backfill\Enums;

/**
 * Why a run stopped, in a form code can branch on.
 *
 * The human-readable sentence stored alongside it is for operators; this is
 * what tells `--queue` mode whether the run stopped because it finished its
 * slice of work and should chain another job, or because something decided it
 * should not continue at all.
 */
enum StopReason: string
{
    case MaxBatches = 'max_batches';
    case CircuitBreaker = 'circuit_breaker';
    case Throttle = 'throttle';
    case Signal = 'signal';
    case Operator = 'operator';

    /**
     * Is it safe to pick straight back up without a human looking first?
     */
    public function isAutomaticallyResumable(): bool
    {
        return $this === self::MaxBatches;
    }
}
