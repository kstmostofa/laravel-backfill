<?php

namespace Kstmostofa\Backfill\Runner;

class ThrottleDecision
{
    public function __construct(
        public readonly int $sleepMs,
        public readonly int $batchSize,
        public readonly bool $pause = false,
        public readonly ?string $reason = null,
        public readonly ?float $lagSeconds = null,
    ) {}

    public static function clear(int $sleepMs, int $batchSize): self
    {
        return new self($sleepMs, $batchSize);
    }

    public static function pause(string $reason, int $sleepMs, int $batchSize, ?float $lagSeconds = null): self
    {
        return new self($sleepMs, $batchSize, true, $reason, $lagSeconds);
    }

    public function engaged(): bool
    {
        return $this->reason !== null;
    }
}
