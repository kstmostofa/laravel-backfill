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
    ) {}

    /**
     * Extrapolate the sampled rows out to the full scope. Deliberately rough —
     * it is there to tell apart "ten minutes" from "three days".
     */
    public function estimatedSeconds(): ?float
    {
        $sampled = count($this->samples);

        if ($this->scope === null || $sampled === 0 || $this->sampleSeconds <= 0) {
            return null;
        }

        return ($this->sampleSeconds / $sampled) * $this->scope;
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
