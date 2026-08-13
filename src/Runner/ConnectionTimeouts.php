<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Bounds how long a single statement, or a wait for a lock, may take.
 *
 * Without this a batch that blocks behind another transaction holds its own
 * locks for as long as it waits, and a backfill quietly becomes the reason
 * production is down. With it, the batch fails fast, rolls back, and is
 * retried as a transient failure.
 */
class ConnectionTimeouts
{
    public function apply(Connection $connection, ?int $statementMs, ?int $lockMs): void
    {
        if ($statementMs === null && $lockMs === null) {
            return;
        }

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->applyMysql($connection, $statementMs, $lockMs),
            'pgsql' => $this->applyPostgres($connection, $statementMs, $lockMs),
            'sqlite' => $this->applySqlite($connection, $lockMs),
            default => null,
        };
    }

    /**
     * Put the session back to server defaults. Connections are pooled and
     * reused, so leaving a timeout behind would apply it to unrelated work.
     */
    public function reset(Connection $connection): void
    {
        $this->quietly(fn () => match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->statement('SET SESSION max_execution_time = 0, innodb_lock_wait_timeout = DEFAULT'),
            'pgsql' => $connection->statement('SET statement_timeout = DEFAULT; SET lock_timeout = DEFAULT'),
            default => null,
        });
    }

    protected function applyMysql(Connection $connection, ?int $statementMs, ?int $lockMs): void
    {
        // max_execution_time only constrains SELECTs on MySQL. It is still
        // worth setting — a runaway keyset query is a real failure mode — but
        // the lock timeout is what protects a blocked write.
        if ($statementMs !== null) {
            $this->quietly(fn () => $connection->statement("SET SESSION max_execution_time = {$statementMs}"));
        }

        if ($lockMs !== null) {
            $seconds = max(1, (int) round($lockMs / 1000));
            $this->quietly(fn () => $connection->statement("SET SESSION innodb_lock_wait_timeout = {$seconds}"));
        }
    }

    protected function applyPostgres(Connection $connection, ?int $statementMs, ?int $lockMs): void
    {
        if ($statementMs !== null) {
            $this->quietly(fn () => $connection->statement("SET statement_timeout = {$statementMs}"));
        }

        if ($lockMs !== null) {
            $this->quietly(fn () => $connection->statement("SET lock_timeout = {$lockMs}"));
        }
    }

    protected function applySqlite(Connection $connection, ?int $lockMs): void
    {
        if ($lockMs !== null) {
            $this->quietly(fn () => $connection->statement("PRAGMA busy_timeout = {$lockMs}"));
        }
    }

    /**
     * A server that will not accept a timeout setting — a managed database
     * with restricted grants, say — should not stop the backfill.
     */
    protected function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            //
        }
    }
}
