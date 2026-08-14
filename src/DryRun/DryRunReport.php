<?php

namespace Kstmostofa\Backfill\DryRun;

class DryRunReport
{
    /**
     * @param  array<int, SampleDiff>  $samples
     * @param  array<string, mixed>  $sideEffects
     * @param  array<string, int>  $events
     */
    public function __construct(
        public readonly string $backfill,
        public readonly ?int $scope,
        public readonly QueryPlan $plan,
        public readonly array $samples,
        public readonly float $sampleSeconds,
        public readonly array $sideEffects = [],
        public readonly array $events = [],
        public readonly int $batchSize = 0,
        /** True when process() ran per row; false on the processBatch() path. */
        public readonly bool $perRow = true,
        /** How many rows were actually timed, which may exceed those shown. */
        public readonly int $timedRows = 0,
    ) {}

    /**
     * Extrapolate the timed sample out to the full scope. Deliberately rough —
     * it is there to tell apart "ten minutes" from "three days".
     *
     * The two paths scale differently, and treating them the same is what made
     * an earlier version report 1.8 hours for a job that took 75 seconds.
     * process() costs per row, so its sample scales by row count. A whole
     * processBatch() costs about the same regardless of how many rows it
     * touches, so its sample scales by batch count.
     */
    public function estimatedSeconds(): ?float
    {
        if ($this->scope === null || $this->sampleSeconds <= 0) {
            return null;
        }

        if (! $this->perRow) {
            return $this->sampleSeconds * max(1, ceil($this->scope / max(1, $this->batchSize)));
        }

        $timed = $this->timedRows ?: count($this->samples);

        return $timed === 0 ? null : ($this->sampleSeconds / $timed) * $this->scope;
    }

    public function estimatedDuration(): ?string
    {
        $seconds = $this->estimatedSeconds();

        if ($seconds === null) {
            return null;
        }

        return match (true) {
            $seconds < 60 => sprintf('~%ds', max(1, (int) round($seconds))),
            $seconds < 3600 => sprintf('~%dm', (int) round($seconds / 60)),
            $seconds < 86400 => sprintf('~%.1fh', $seconds / 3600),
            default => sprintf('~%.1f days', $seconds / 86400),
        };
    }

    public function wouldChangeNothing(): bool
    {
        return collect($this->samples)->every(fn (SampleDiff $diff) => $diff->unchanged());
    }

    public function failingSamples(): int
    {
        return collect($this->samples)->filter(fn (SampleDiff $diff) => $diff->error !== null)->count();
    }

    public function hasSideEffects(): bool
    {
        return $this->sideEffects !== [];
    }
}
