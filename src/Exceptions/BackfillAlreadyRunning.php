<?php

namespace Kstmostofa\Backfill\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

class BackfillAlreadyRunning extends RuntimeException
{
    public static function make(string $name, ?string $owner, ?Carbon $since): self
    {
        $when = $since ? $since->format('H:i') : 'an unknown time';
        $where = $owner ?: 'another process';

        return new self("Backfill [{$name}] is already running since {$when} on {$where}.");
    }
}
