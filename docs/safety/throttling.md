# Throttling

A backfill that saturates a primary is bad. One that pushes read replicas so far behind that the application starts serving stale data is worse, and much harder to diagnose — nothing errors, the numbers are just wrong for a while.

Throttling is off by default. Turn it on for anything that runs against a replicated production database.

```php
'throttle' => [
    'enabled' => true,
    'connection' => 'mysql_replica',
    'lag_soft' => 5,
    'lag_hard' => 30,
    'lag_timeout' => 600,
    'poll_ms' => 1000,
    'min_batch_size' => 50,
    'slow_batch_multiplier' => 5,
],
```

## What it does

**Below `lag_soft`** — full speed, no interference at all.

**Between soft and hard** — slows down in proportion to how far into the band the lag is, from 2× the configured sleep at the soft edge up to 10× approaching hard, and halves the batch size so each transaction holds its locks for less time. The batch never shrinks below `min_batch_size`.

```
Replication lag 12.4s is above the 5.0s soft limit:
sleeping 640ms and halving the batch to 500.
```

**Above `lag_hard`** — stops issuing batches entirely and polls until the replicas recover. If they have not recovered within `lag_timeout`, the run pauses itself rather than pushing them further behind:

```
Replication lag stayed at 41.2s (limit 30.0s) for 600s. Pausing rather than
pushing the replicas further behind — resume once they have caught up.
```

That pause fires a [`BackfillPaused` event](/features/events) flagged as automatic, which means it also triggers a [notification](/features/notifications) and stops [queue mode](/features/queue) from chaining another job.

## Reading the lag

Where the number comes from depends on your database, and this is the part worth checking before you rely on it.

**PostgreSQL** works either way round. Pointed at a replica, it reads `pg_last_xact_replay_timestamp()`. Pointed at a primary, it falls back to the worst `replay_lag` across `pg_stat_replication`, so you get a useful signal without configuring a second connection.

**MySQL has no primary-side equivalent.** `SHOW REPLICA STATUS` only reports something when you ask a replica. So on MySQL you must point `throttle.connection` at a replica connection, or throttling stays inactive.

**SQLite** has no replication, so the monitor returns null and throttling does nothing.

::: tip An unreadable signal counts as healthy
If the lag cannot be read — a missing `REPLICATION CLIENT` grant, no `pg_monitor` role, no replicas connected — the monitor returns null and the runner proceeds at full speed.

Stalling a backfill forever because a permission is missing would be worse than not throttling. If you are relying on throttling, verify it reports a number in staging rather than assuming.
:::

## The other signal

Replication lag does not see everything. A batch that suddenly takes far longer than usual means contention somewhere the replica view cannot show you — a competing transaction, a lock convoy, an index being rebuilt.

The runner keeps a rolling median of the last 20 batch durations. When a batch exceeds `slow_batch_multiplier` times that median, it backs off the same way:

```
Last batch took 4,200ms against a 180ms median:
backing off in case the table is under contention.
```

Set `slow_batch_multiplier` to `0` to disable this and rely on lag alone.

## Watching it happen

Throttle decisions are surfaced three ways:

- Printed above the progress bar as they happen
- Dispatched as [`ThrottleEngaged`](/features/events) events, with the decision attached
- Recorded on the run's `meta.stop_reason` when the throttle is what paused it

## Testing it

Real replication lag cannot be produced on demand, and waiting for it would be a race. Swap the monitor for one with scripted readings:

```php
use Kstmostofa\Backfill\Runner\LagMonitor;

$this->app->instance(LagMonitor::class, new class extends LagMonitor {
    public function lagSeconds(): ?float
    {
        return 90.0;   // permanently unhappy replicas
    }
});

$run = runBackfill(BackfillUserSlugs::class);

expect($run->status)->toBe(RunStatus::Paused)
    ->and($run->meta['stop_reason'])->toContain('Replication lag stayed');
```

Keep `lag_timeout` and `poll_ms` small in tests, or each one waits for the real timeout.
