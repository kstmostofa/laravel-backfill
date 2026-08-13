<?php

namespace Kstmostofa\Backfill\Parameters;

use Illuminate\Support\Str;

/**
 * A single input an operator fills in before running a backfill.
 *
 * The point of declaring these rather than accepting free text is that support
 * staff can drive a backfill without a developer, and cannot drive it off a
 * cliff: an id list has a ceiling, a select has fixed options, and everything
 * is validated before a job is queued.
 */
class Parameter
{
    public bool $required = false;

    public ?int $max = null;

    public ?int $min = null;

    public ?string $help = null;

    public ?string $placeholder = null;

    public mixed $default = null;

    /** @var array<string|int, string> */
    public array $options = [];

    protected function __construct(
        public readonly string $key,
        public readonly string $type,
        public string $label,
    ) {}

    public static function text(string $key, ?string $label = null): static
    {
        return new static($key, 'text', $label ?? static::humanise($key));
    }

    public static function textarea(string $key, ?string $label = null): static
    {
        return new static($key, 'textarea', $label ?? static::humanise($key));
    }

    /**
     * A pasted list of identifiers — commas, spaces or newlines, whichever the
     * operator's spreadsheet produced.
     */
    public static function ids(string $key, ?string $label = null): static
    {
        return new static($key, 'ids', $label ?? static::humanise($key));
    }

    public static function number(string $key, ?string $label = null): static
    {
        return new static($key, 'number', $label ?? static::humanise($key));
    }

    public static function boolean(string $key, ?string $label = null): static
    {
        return new static($key, 'boolean', $label ?? static::humanise($key));
    }

    /**
     * @param  array<string|int, string>  $options
     */
    public static function select(string $key, array $options, ?string $label = null): static
    {
        $parameter = new static($key, 'select', $label ?? static::humanise($key));
        $parameter->options = $options;

        return $parameter;
    }

    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    /**
     * For an id list this is the most identifiers allowed; for a number it is
     * the largest value.
     */
    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    public function min(int $min): static
    {
        $this->min = $min;

        return $this;
    }

    public function help(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Normalise raw input and report anything wrong with it.
     *
     * @return array{0: mixed, 1: array<int, string>}
     */
    public function validate(mixed $value): array
    {
        $errors = [];

        $value = match ($this->type) {
            'ids' => $this->normaliseIds($value),
            'number' => $this->normaliseNumber($value),
            'boolean' => (bool) $value,
            default => is_string($value) ? trim($value) : $value,
        };

        if ($this->isEmpty($value)) {
            if ($this->required) {
                $errors[] = "{$this->label} is required.";
            }

            return [$this->default, $errors];
        }

        if ($this->type === 'ids') {
            if ($this->max !== null && count($value) > $this->max) {
                $errors[] = sprintf(
                    '%s has %s entries, which is more than the %s allowed.',
                    $this->label,
                    number_format(count($value)),
                    number_format($this->max),
                );
            }

            if ($this->min !== null && count($value) < $this->min) {
                $errors[] = "{$this->label} needs at least {$this->min} entries.";
            }
        }

        if ($this->type === 'number') {
            if ($this->max !== null && $value > $this->max) {
                $errors[] = "{$this->label} cannot be above {$this->max}.";
            }

            if ($this->min !== null && $value < $this->min) {
                $errors[] = "{$this->label} cannot be below {$this->min}.";
            }
        }

        if ($this->type === 'select' && ! array_key_exists($value, $this->options)) {
            $errors[] = "{$this->label} is not one of the allowed options.";
        }

        return [$value, $errors];
    }

    /**
     * @return array<int, string>
     */
    protected function normaliseIds(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value)) {
            $parts = preg_split('/[\s,;]+/', $value) ?: [];
        } else {
            $parts = [];
        }

        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn (string $part) => $part !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function normaliseNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    protected function isEmpty(mixed $value): bool
    {
        // An unticked checkbox is an answer, not a missing one.
        if ($this->type === 'boolean') {
            return false;
        }

        return $value === null || $value === '' || $value === [];
    }

    protected static function humanise(string $key): string
    {
        return Str::of($key)->replace(['_', '-'], ' ')->trim()->ucfirst()->toString();
    }
}
