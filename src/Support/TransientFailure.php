<?php

namespace Kstmostofa\Backfill\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Distinguishes "the database was busy" from "your code is wrong".
 *
 * A deadlock, lock timeout or dropped connection leaves the batch rolled back
 * cleanly, so retrying it is safe and usually succeeds. Anything else — a bad
 * column, a constraint you did not expect — will fail identically forever, and
 * retrying it just delays the error while holding a lock.
 */
class TransientFailure
{
    /**
     * SQLSTATE codes that mean "try again".
     */
    protected const STATES = [
        '40001',  // serialization failure
        '40P01',  // deadlock detected (PostgreSQL)
        '55P03',  // lock not available (PostgreSQL)
        '57014',  // query cancelled — statement timeout (PostgreSQL)
        '08000',  // connection exception
        '08003',  // connection does not exist
        '08006',  // connection failure
        'HY000',  // driver-specific; the MySQL codes below narrow it down
    ];

    /**
     * MySQL driver error codes that mean "try again".
     */
    protected const MYSQL_CODES = [
        1205,  // lock wait timeout exceeded
        1213,  // deadlock found when trying to get lock
        1614,  // transaction branch was rolled back
        2006,  // server has gone away
        2013,  // lost connection during query
        3024,  // query execution exceeded max_execution_time
    ];

    public static function matches(Throwable $e): bool
    {
        if (! $e instanceof QueryException && ! $e instanceof PDOException) {
            return static::previousMatches($e);
        }

        $state = (string) $e->getCode();

        if ($state === 'HY000' || $state === '') {
            return static::matchesDriverCode($e);
        }

        if (in_array($state, static::STATES, true)) {
            return true;
        }

        return static::matchesDriverCode($e);
    }

    protected static function matchesDriverCode(Throwable $e): bool
    {
        $info = $e instanceof QueryException || $e instanceof PDOException
            ? ($e->errorInfo ?? null)
            : null;

        $driverCode = is_array($info) && isset($info[1]) ? (int) $info[1] : null;

        if ($driverCode !== null && in_array($driverCode, static::MYSQL_CODES, true)) {
            return true;
        }

        // SQLite reports contention through the message rather than a code.
        $message = strtolower($e->getMessage());

        foreach (['database is locked', 'database table is locked', 'deadlock', 'lock wait timeout'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function previousMatches(Throwable $e): bool
    {
        $previous = $e->getPrevious();

        return $previous !== null && static::matches($previous);
    }
}
