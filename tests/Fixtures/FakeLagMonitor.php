<?php

namespace Kstmostofa\Backfill\Tests\Fixtures;

use Kstmostofa\Backfill\Runner\LagMonitor;

/**
 * Feeds the throttle scripted lag readings. Real replication lag cannot be
 * produced on demand in a test, and waiting for it would be a race.
 */
class FakeLagMonitor extends LagMonitor
{
    /** Readings returned in order; the last one repeats once exhausted. */
    public array $readings = [];

    public int $calls = 0;

    public function __construct(array $readings = [])
    {
        $this->readings = $readings;
    }

    public function lagSeconds(): ?float
    {
        $this->calls++;

        if ($this->readings === []) {
            return null;
        }

        return count($this->readings) === 1
            ? $this->readings[0]
            : array_shift($this->readings);
    }
}
