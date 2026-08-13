<?php

namespace Kstmostofa\Backfill\Tests\Fixtures;

use PDOException;

/**
 * A stand-in for what MySQL and PostgreSQL throw when two transactions
 * deadlock. Building it by hand is the only way to exercise the retry path
 * deterministically — provoking a real deadlock in a test is a race.
 */
class FakeDeadlockException extends PDOException
{
    public function __construct(string $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
    {
        parent::__construct($message);

        $this->code = '40001';
        $this->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
    }
}
