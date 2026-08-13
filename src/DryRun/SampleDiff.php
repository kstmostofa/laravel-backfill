<?php

namespace Kstmostofa\Backfill\DryRun;

class SampleDiff
{
    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $changes = [],
        public readonly ?string $error = null,
        public readonly bool $deleted = false,
    ) {}

    public function unchanged(): bool
    {
        return $this->error === null && ! $this->deleted && $this->changes === [];
    }

    public function summary(): string
    {
        if ($this->error !== null) {
            return 'would fail: '.$this->error;
        }

        if ($this->deleted) {
            return 'row would be deleted';
        }

        if ($this->changes === []) {
            return 'no change';
        }

        return collect($this->changes)
            ->map(fn (array $change, string $column) => sprintf(
                '%s: %s → %s',
                $column,
                static::render($change['from']),
                static::render($change['to']),
            ))
            ->implode(', ');
    }

    public static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value),
        };
    }
}
