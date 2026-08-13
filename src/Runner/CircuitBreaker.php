<?php

namespace Kstmostofa\Backfill\Runner;

/**
 * Stops a run whose failures have stopped looking like bad rows and started
 * looking like a bad assumption.
 *
 * Two things make the rate meaningful rather than annoying. It is only
 * consulted once enough rows have been attempted to mean anything, so a
 * two-row batch with one bad row does not halt a healthy run. And it counts
 * only the current session, not the run's lifetime — otherwise a run that
 * tripped, got fixed, and was resumed would be judged forever on the failures
 * that came before the fix, and could never finish.
 */
class CircuitBreaker
{
    public function enabled(): bool
    {
        return (bool) config('backfill.circuit_breaker.enabled', true);
    }

    public function shouldTrip(int $processed, int $failed): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $attempted = $processed + $failed;

        if ($attempted < $this->minSample()) {
            return false;
        }

        return $this->failureRate($processed, $failed) > $this->maxRate();
    }

    public function reason(int $processed, int $failed, string $backfill): string
    {
        return sprintf(
            'Circuit breaker tripped: %s of %s rows failed in this session (%.0f%%, limit %.0f%%). '
            .'That rate usually means something systemic rather than bad rows — '
            .'check `backfill:status %s`, fix the cause, then resume.',
            number_format($failed),
            number_format($processed + $failed),
            $this->failureRate($processed, $failed) * 100,
            $this->maxRate() * 100,
            $backfill,
        );
    }

    public function failureRate(int $processed, int $failed): float
    {
        $attempted = $processed + $failed;

        return $attempted === 0 ? 0.0 : $failed / $attempted;
    }

    protected function maxRate(): float
    {
        return (float) config('backfill.circuit_breaker.max_failure_rate', 0.25);
    }

    protected function minSample(): int
    {
        return (int) config('backfill.circuit_breaker.min_sample', 50);
    }
}
