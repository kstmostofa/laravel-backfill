<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backfill Class Path
    |--------------------------------------------------------------------------
    | Directory scanned for backfill classes. Classes must extend
    | Kstmostofa\Backfill\Backfill and be autoloadable.
    */
    'path' => app_path('Backfills'),

    /*
    |--------------------------------------------------------------------------
    | Bookkeeping Connection
    |--------------------------------------------------------------------------
    | Connection used for the backfill_runs / backfill_run_errors tables.
    | Null uses the default connection. Keep this the SAME connection as the
    | data you are backfilling if you want the cursor to commit in the same
    | transaction as the work (strongest crash guarantee).
    */
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | Per-class $batchSize / $sleepMs properties override these.
    */
    'batch_size' => 1000,
    'sleep_ms' => 0,

    /*
    |--------------------------------------------------------------------------
    | Stale Run Detection
    |--------------------------------------------------------------------------
    | A run whose heartbeat is older than this many seconds is considered
    | crashed ("interrupted") and may be resumed by the next backfill:run.
    */
    'stale_after' => 120,

    /*
    |--------------------------------------------------------------------------
    | Statement and Lock Timeouts
    |--------------------------------------------------------------------------
    | Applied to the runner's session so a batch that blocks fails and retries
    | instead of holding a lock on the table indefinitely. Values in
    | milliseconds; null leaves the server default alone.
    |
    | Note: MySQL's max_execution_time only constrains read statements. The
    | lock timeout is what protects you from a blocked write there.
    */
    'timeouts' => [
        'statement' => null,
        'lock' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Retries
    |--------------------------------------------------------------------------
    | Deadlocks, lock timeouts and dropped connections are transient: the batch
    | rolled back cleanly, so retrying it is safe. Anything else fails the run
    | immediately rather than hammering a real bug.
    */
    'retry' => [
        'max_batch_retries' => 3,
        'base_delay_ms' => 250,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    | A few bad rows are normal. Most rows failing means something systemic —
    | a missing column, a dead API — and the run should stop rather than burn
    | through eight million rows recording the same error.
    |
    | The rate is cumulative and only evaluated once min_sample rows have been
    | attempted, so a couple of failures early on cannot trip it.
    */
    'circuit_breaker' => [
        'enabled' => true,
        'max_failure_rate' => 0.25,
        'min_sample' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Adaptive Throttling
    |--------------------------------------------------------------------------
    | Watches replication lag and backs off before the replicas fall over.
    | Below lag_soft it runs at full speed; between soft and hard it slows down
    | and halves the batch; above lag_hard it stops issuing batches until the
    | replicas recover, and auto-pauses if they have not after lag_timeout.
    |
    | 'connection' should name a replica connection to measure lag on. Left
    | null, the runner measures what it can from its own connection —
    | pg_stat_replication on a PostgreSQL primary, or replica status if the
    | connection is itself a replica.
    */
    'throttle' => [
        'enabled' => false,
        'connection' => null,
        'lag_soft' => 5,
        'lag_hard' => 30,
        'lag_timeout' => 600,
        'poll_ms' => 1000,
        'min_batch_size' => 50,

        // Also back off when a batch takes this many times longer than the
        // rolling median, which catches pressure that lag does not show.
        'slow_batch_multiplier' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dry Run
    |--------------------------------------------------------------------------
    | How many rows to actually process, inside a transaction that is rolled
    | back, in order to show real before/after diffs.
    */
    'dry_run' => [
        'samples' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Guards
    |--------------------------------------------------------------------------
    | max_rows_without_confirmation refuses to start a run larger than this
    | without --force. Deploy freeze windows refuse to start during the hours
    | you have decided nobody should be touching production.
    */
    'guards' => [
        'max_rows_without_confirmation' => 1000000,

        'deploy_freeze' => [
            'enabled' => false,
            'timezone' => null,
            'windows' => [
                // ['days' => ['fri'], 'from' => '15:00', 'to' => '23:59'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Audit Trail
    |--------------------------------------------------------------------------
    | Records one row per batch in backfill_run_batches. Useful for working out
    | after the fact where a run slowed down; off by default because it is a
    | write per batch.
    */
    'record_batches' => false,

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    | Finished runs older than this are removed by Laravel's `model:prune`.
    */
    'prune_runs_after_days' => 90,

];
