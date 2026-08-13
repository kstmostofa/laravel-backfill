<?php

namespace Kstmostofa\Backfill\Tests;

/**
 * The chaos tests fork and kill a child process, so both processes need a
 * database that outlives a connection — an in-memory one would not be shared.
 */
abstract class ChaosTestCase extends TestCase
{
    protected ?string $chaosDatabase = null;

    protected function databasePath(): string
    {
        if ($this->chaosDatabase === null) {
            $this->chaosDatabase = tempnam(sys_get_temp_dir(), 'backfill_chaos_').'.sqlite';
            touch($this->chaosDatabase);
        }

        return $this->chaosDatabase;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->chaosDatabase && file_exists($this->chaosDatabase)) {
            @unlink($this->chaosDatabase);
        }

        $this->chaosDatabase = null;
    }
}
