<?php

namespace Kstmostofa\Backfill\Runner;

/**
 * Slows the runner down before the replicas notice it.
 *
 * A backfill that saturates a primary is bad; one that pushes replicas so far
 * behind that reads start serving stale data is worse, and much harder to
 * diagnose from the application side. Below the soft threshold this does
 * nothing at all.
 */
class Throttle
{
    public function __construct(protected LagMonitor $lag) {}

    public function enabled(): bool
    {
        return (bool) config('backfill.throttle.enabled', false);
    }

    /**
     * Decide how to proceed before issuing the next batch. May block while
     * waiting for replicas to catch up.
     */
    public function evaluate(int $baseSleepMs, int $baseBatchSize, ?float $medianMs = null, ?int $lastDurationMs = null): ThrottleDecision
    {
        if (! $this->enabled()) {
            return ThrottleDecision::clear($baseSleepMs, $baseBatchSize);
        }

        $lagSeconds = $this->lag->lagSeconds();

        if ($lagSeconds !== null && $lagSeconds >= $this->hard()) {
            $lagSeconds = $this->waitForRecovery($lagSeconds);

            if ($lagSeconds !== null && $lagSeconds >= $this->hard()) {
                return ThrottleDecision::pause(
                    sprintf(
                        'Replication lag stayed at %.1fs (limit %.1fs) for %ds. Pausing rather than '
                        .'pushing the replicas further behind — resume once they have caught up.',
                        $lagSeconds,
                        $this->hard(),
                        $this->timeout(),
                    ),
                    $baseSleepMs,
                    $baseBatchSize,
                    $lagSeconds,
                );
            }
        }

        if ($lagSeconds !== null && $lagSeconds >= $this->soft()) {
            return $this->easeOff($lagSeconds, $baseSleepMs, $baseBatchSize);
        }

        return $this->checkBatchDuration($baseSleepMs, $baseBatchSize, $medianMs, $lastDurationMs);
    }

    /**
     * Between the soft and hard thresholds: slow down in proportion to how far
     * into the band we are, and halve the batch so each transaction holds its
     * locks for less time.
     */
    protected function easeOff(float $lagSeconds, int $sleepMs, int $batchSize): ThrottleDecision
    {
        $band = max(0.001, $this->hard() - $this->soft());
        $position = min(1.0, ($lagSeconds - $this->soft()) / $band);

        // Scale sleep from 2x at the soft edge up to 10x approaching hard.
        $multiplier = 2 + (8 * $position);
        $scaledSleep = (int) round(max($sleepMs, 50) * $multiplier);

        $reducedBatch = max($this->minBatchSize(), (int) floor($batchSize / 2));

        return new ThrottleDecision(
            $scaledSleep,
            $reducedBatch,
            false,
            sprintf(
                'Replication lag %.1fs is above the %.1fs soft limit: sleeping %dms and halving the batch to %d.',
                $lagSeconds,
                $this->soft(),
                $scaledSleep,
                $reducedBatch,
            ),
            $lagSeconds,
        );
    }

    /**
     * Lag is not the only signal. A batch that suddenly takes far longer than
     * usual means contention somewhere the replica view cannot see.
     */
    protected function checkBatchDuration(int $sleepMs, int $batchSize, ?float $medianMs, ?int $lastDurationMs): ThrottleDecision
    {
        $multiplier = (float) config('backfill.throttle.slow_batch_multiplier', 5);

        if ($medianMs === null || $lastDurationMs === null || $medianMs <= 0 || $multiplier <= 0) {
            return ThrottleDecision::clear($sleepMs, $batchSize);
        }

        if ($lastDurationMs < $medianMs * $multiplier) {
            return ThrottleDecision::clear($sleepMs, $batchSize);
        }

        return new ThrottleDecision(
            (int) round(max($sleepMs, 50) * 2),
            max($this->minBatchSize(), (int) floor($batchSize / 2)),
            false,
            sprintf(
                'Last batch took %dms against a %dms median: backing off in case the table is under contention.',
                $lastDurationMs,
                (int) round($medianMs),
            ),
        );
    }

    /**
     * Poll until lag drops back under the hard threshold, or we run out of
     * patience. Returns the last reading.
     */
    protected function waitForRecovery(float $lagSeconds): ?float
    {
        $deadline = microtime(true) + $this->timeout();
        $pollMs = max(50, (int) config('backfill.throttle.poll_ms', 1000));

        while (microtime(true) < $deadline) {
            usleep($pollMs * 1000);

            $lagSeconds = $this->lag->lagSeconds();

            if ($lagSeconds === null || $lagSeconds < $this->hard()) {
                return $lagSeconds;
            }
        }

        return $lagSeconds;
    }

    protected function soft(): float
    {
        return (float) config('backfill.throttle.lag_soft', 5);
    }

    protected function hard(): float
    {
        return (float) config('backfill.throttle.lag_hard', 30);
    }

    protected function timeout(): int
    {
        return (int) config('backfill.throttle.lag_timeout', 600);
    }

    protected function minBatchSize(): int
    {
        return max(1, (int) config('backfill.throttle.min_batch_size', 50));
    }
}
