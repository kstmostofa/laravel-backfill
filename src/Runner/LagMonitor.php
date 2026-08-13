<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports replication lag in seconds, or null when there is no signal to read.
 *
 * Null is a meaningful answer and is treated as "do not throttle": guessing
 * that an unmeasurable replica is healthy is better than stalling a run
 * forever because a permission is missing.
 */
class LagMonitor
{
    public function lagSeconds(): ?float
    {
        try {
            $connection = $this->connection();

            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $this->mysqlLag($connection),
                'pgsql' => $this->postgresLag($connection),
                default => null,
            };
        } catch (Throwable) {
            // A missing grant (REPLICATION CLIENT, pg_monitor) must not take
            // down the backfill.
            return null;
        }
    }

    protected function connection(): Connection
    {
        $name = config('backfill.throttle.connection') ?: config('backfill.connection');

        return DB::connection($name);
    }

    /**
     * Reads the replica's own view of how far behind it is. Only meaningful
     * when pointed at a replica — a primary has nothing to report.
     */
    protected function mysqlLag(Connection $connection): ?float
    {
        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $statement) {
            try {
                $rows = $connection->select($statement);
            } catch (Throwable) {
                continue;
            }

            if ($rows === []) {
                continue;
            }

            $row = (array) $rows[0];

            foreach (['Seconds_Behind_Source', 'Seconds_Behind_Master'] as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    return (float) $row[$column];
                }
            }
        }

        return null;
    }

    /**
     * On a replica, how stale the last replayed transaction is. On a primary,
     * the worst replay lag across connected replicas.
     */
    protected function postgresLag(Connection $connection): ?float
    {
        $replica = $connection->select(
            'select extract(epoch from (now() - pg_last_xact_replay_timestamp())) as lag'
        );

        if ($replica !== [] && ($replica[0]->lag ?? null) !== null) {
            return (float) $replica[0]->lag;
        }

        $primary = $connection->select(
            'select extract(epoch from max(replay_lag)) as lag from pg_stat_replication'
        );

        if ($primary !== [] && ($primary[0]->lag ?? null) !== null) {
            return (float) $primary[0]->lag;
        }

        return null;
    }
}
