# Configuration

```bash
php artisan vendor:publish --tag=backfill-config
```

Every key in `config/backfill.php`, with its default.

## Discovery and storage

```php
'path' => app_path('Backfills'),
'connection' => null,
```

| Key | Default | Description |
| --- | --- | --- |
| `path` | `app/Backfills` | Directory scanned for backfill classes |
| `connection` | `null` | Connection for the bookkeeping tables |

::: tip Keep `connection` on the same database as your data
The cursor commits in the same transaction as the work only when they share a connection. Splitting them breaks the guarantee that the cursor and the data agree.
:::

## Defaults

```php
'batch_size' => 1000,
'sleep_ms' => 0,
'stale_after' => 120,
```

| Key | Default | Description |
| --- | --- | --- |
| `batch_size` | `1000` | Rows per batch; the class property wins |
| `sleep_ms` | `0` | Pause between batches; the class property wins |
| `stale_after` | `120` | Seconds without a heartbeat before a run counts as crashed |

`stale_after` decides two things: when an abandoned run becomes resumable, and when a lock left by a killed process can be taken over. Too low and a slow batch looks like a crash; too high and a genuinely dead run blocks its replacement.

## Timeouts

```php
'timeouts' => [
    'statement' => null,
    'lock' => null,
],
```

Milliseconds; `null` leaves the server default alone. See [failures and retries](/safety/failures#statement-and-lock-timeouts).

## Retries

```php
'retry' => [
    'max_batch_retries' => 3,
    'base_delay_ms' => 250,
],
```

Applies only to [transient failures](/safety/failures#a-busy-database) — deadlocks, lock timeouts, dropped connections. The delay doubles each attempt.

## Circuit breaker

```php
'circuit_breaker' => [
    'enabled' => true,
    'max_failure_rate' => 0.25,
    'min_sample' => 50,
],
```

The rate is cumulative **within a session** and is only judged once `min_sample` rows have been attempted. See [why that matters](/safety/failures#a-systemic-problem).

## Throttling

```php
'throttle' => [
    'enabled' => false,
    'connection' => null,
    'lag_soft' => 5,
    'lag_hard' => 30,
    'lag_timeout' => 600,
    'poll_ms' => 1000,
    'min_batch_size' => 50,
    'slow_batch_multiplier' => 5,
],
```

| Key | Description |
| --- | --- |
| `connection` | Replica connection to measure lag on. **Required on MySQL** |
| `lag_soft` | Seconds of lag at which to start slowing down |
| `lag_hard` | Seconds at which to stop issuing batches |
| `lag_timeout` | Seconds to wait for recovery before pausing the run |
| `poll_ms` | How often to re-check while waiting |
| `min_batch_size` | Floor the throttle will not shrink past |
| `slow_batch_multiplier` | Back off when a batch exceeds this × the rolling median; `0` disables |

See [throttling](/safety/throttling).

## Dry run

```php
'dry_run' => ['samples' => 5],
```

Rows genuinely processed and rolled back. More samples give a better duration estimate at the cost of a longer transaction.

## Production guards

```php
'guards' => [
    'max_rows_without_confirmation' => 1_000_000,
    'deploy_freeze' => [
        'enabled' => false,
        'timezone' => null,
        'windows' => [
            // ['days' => ['fri'], 'from' => '15:00', 'to' => '23:59'],
        ],
    ],
],
```

`max_rows_without_confirmation` set to `null` disables the ceiling and skips the row count entirely. `timezone` falls back to `app.timezone`. See [production guards](/safety/guards).

## Queue mode

```php
'queue' => [
    'connection' => null,
    'queue' => null,
    'batches_per_job' => 25,
],
```

See [running on the queue](/features/queue).

## Notifications

```php
'notifications' => [
    'enabled' => false,
    'on' => ['completed', 'failed', 'paused'],
    'mail' => null,
    'slack_webhook' => null,
],
```

See [notifications](/features/notifications).

## Dashboard

```php
'dashboard' => [
    'enabled' => false,
    'path' => 'backfills',
    'operator_path' => 'backfills/tasks',
    'middleware' => ['web'],
],
```

Enabling registers the routes; **authorisation is separate** and closed by default. See [the dashboard](/features/dashboard#authorisation).

## Auditing and retention

```php
'record_batches' => false,
'prune_runs_after_days' => 90,
```

`record_batches` writes one row per batch to `backfill_run_batches` — needed for the dashboard sparkline, off by default because it is a write per batch.

`prune_runs_after_days` is the retention for finished runs under `model:prune`. Paused and interrupted runs are never pruned.
