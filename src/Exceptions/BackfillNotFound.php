<?php

namespace Kstmostofa\Backfill\Exceptions;

use InvalidArgumentException;

class BackfillNotFound extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function named(string $name, array $known = []): static
    {
        $message = "No backfill named [{$name}] was found.";

        if ($known !== []) {
            $message .= ' Known backfills: '.implode(', ', $known).'.';
        }

        return new static($message);
    }
}
