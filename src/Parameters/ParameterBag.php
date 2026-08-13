<?php

namespace Kstmostofa\Backfill\Parameters;

use Kstmostofa\Backfill\Backfill;

class ParameterBag
{
    /**
     * Validate operator input against a backfill's declared parameters.
     *
     * Anything not declared is dropped rather than passed through: the whole
     * point of the operator panel is that the inputs are the ones the developer
     * chose to expose.
     *
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, errors: array<int, string>}
     */
    public static function validate(Backfill $backfill, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($backfill->parameters() as $parameter) {
            [$value, $parameterErrors] = $parameter->validate($input[$parameter->key] ?? null);

            $values[$parameter->key] = $value;
            $errors = array_merge($errors, $parameterErrors);
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * A short description of the values chosen, for the run's audit trail.
     *
     * @param  array<string, mixed>  $values
     */
    public static function summarise(Backfill $backfill, array $values): string
    {
        $parts = [];

        foreach ($backfill->parameters() as $parameter) {
            $value = $values[$parameter->key] ?? null;

            $parts[] = $parameter->label.': '.match (true) {
                $value === null => 'none',
                is_bool($value) => $value ? 'yes' : 'no',
                is_array($value) => count($value).' entries',
                default => (string) $value,
            };
        }

        return implode(', ', $parts);
    }
}
