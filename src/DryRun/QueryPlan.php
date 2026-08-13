<?php

namespace Kstmostofa\Backfill\DryRun;

use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Asks the database how it intends to run the keyset query.
 *
 * The question is narrower than "is any index used". A backfill can happily use
 * an index for its WHERE clause and still sort the whole table on every batch
 * because the cursor column is unindexed — which is the failure that turns a
 * ten-minute job into a three-day one. So what matters is whether the ORDER BY
 * is satisfied by an index, which each engine reports in its own way:
 * a filesort on MySQL, a Sort node on PostgreSQL, a temp b-tree on SQLite.
 */
class QueryPlan
{
    public function __construct(
        public readonly ?bool $usesIndex,
        public readonly string $detail,
    ) {}

    public static function unknown(string $detail = 'Could not read a query plan for this connection.'): self
    {
        return new self(null, $detail);
    }

    /**
     * @param  mixed  $cursorSample  A representative cursor value. Without the
     *                               `key > ?` predicate the plan describes a
     *                               query the runner never actually issues.
     */
    public static function for(Builder $query, string $key, mixed $cursorSample = null, int $limit = 1000): self
    {
        try {
            $keyset = (clone $query)->reorder()->orderBy($key)->limit($limit);

            if ($cursorSample !== null) {
                $keyset->where($key, '>', $cursorSample);
            }

            $connection = $keyset->getConnection();
            $driver = $connection->getDriverName();
            $prefix = $driver === 'sqlite' ? 'EXPLAIN QUERY PLAN' : 'EXPLAIN';

            $rows = $connection->select("{$prefix} {$keyset->toSql()}", $keyset->getBindings());

            return static::parse($driver, $rows);
        } catch (Throwable $e) {
            return static::unknown('Could not read a query plan: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, object|array>  $rows
     */
    public static function parse(string $driver, array $rows): self
    {
        if ($rows === []) {
            return static::unknown();
        }

        return match ($driver) {
            'mysql', 'mariadb' => static::fromMysql($rows),
            'pgsql' => static::fromPostgres($rows),
            'sqlite' => static::fromSqlite($rows),
            default => static::unknown(),
        };
    }

    protected static function fromMysql(array $rows): self
    {
        $row = (array) $rows[0];
        $index = $row['key'] ?? null;
        $type = $row['type'] ?? '';
        $extra = (string) ($row['Extra'] ?? '');

        if (str_contains($extra, 'Using filesort')) {
            return new self(false, sprintf(
                'Sorts every batch (filesort, type=%s). Add an index on the cursor column.',
                $type ?: 'unknown',
            ));
        }

        if ($index === null || $type === 'ALL') {
            return new self(false, sprintf(
                'Full table scan (type=%s). Add an index on the cursor column.',
                $type ?: 'unknown',
            ));
        }

        return new self(true, sprintf('Walks index %s (type=%s), no sort.', $index, $type ?: 'unknown'));
    }

    protected static function fromPostgres(array $rows): self
    {
        $lines = array_map(fn ($row) => trim(((array) $row)['QUERY PLAN'] ?? ''), $rows);
        $plan = implode(' ', $lines);

        if ($plan === '') {
            return static::unknown();
        }

        if (preg_match('/(^|\s)Sort\s+\(cost/', $plan) || str_contains($plan, 'Sort Key:')) {
            return new self(false,
                'Sorts every batch (Sort node). Add an index on the cursor column. '
                .'Note that PostgreSQL also chooses a sort on small tables regardless, '
                .'so re-check this against production-sized data.'
            );
        }

        if (str_contains($plan, 'Index Scan') || str_contains($plan, 'Index Only Scan')) {
            return new self(true, 'Walks an index, no sort.');
        }

        if (str_contains($plan, 'Seq Scan')) {
            return new self(false, 'Sequential scan. Add an index on the cursor column.');
        }

        return static::unknown('Plan: '.$plan);
    }

    protected static function fromSqlite(array $rows): self
    {
        $details = array_map(fn ($row) => trim(((array) $row)['detail'] ?? ''), $rows);
        $plan = implode('; ', $details);

        if ($plan === '') {
            return static::unknown();
        }

        if (str_contains($plan, 'USE TEMP B-TREE FOR ORDER BY')) {
            return new self(false, 'Sorts every batch (temp b-tree). Add an index on the cursor column.');
        }

        // Walking the integer primary key is SQLite's own phrasing for exactly
        // the ordered access keyset pagination wants.
        if (str_contains($plan, 'USING INTEGER PRIMARY KEY') || str_contains($plan, 'USING INDEX')) {
            return new self(true, $plan);
        }

        if (str_starts_with($plan, 'SCAN')) {
            return new self(false, $plan.' — no index on the cursor column.');
        }

        return static::unknown('Plan: '.$plan);
    }

    public function label(): string
    {
        return match ($this->usesIndex) {
            true => 'indexed',
            false => 'NOT INDEXED',
            null => 'unknown',
        };
    }
}
