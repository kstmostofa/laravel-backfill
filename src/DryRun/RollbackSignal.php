<?php

namespace Kstmostofa\Backfill\DryRun;

use RuntimeException;

/**
 * Thrown to force the dry run's transaction to roll back. Never escapes the
 * DryRunner.
 */
class RollbackSignal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dry run complete; rolling back.');
    }
}
