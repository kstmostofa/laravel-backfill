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

];
