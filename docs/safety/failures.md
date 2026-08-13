# Failures and retries

Three different kinds of failure, handled three different ways.

## A bad row

One row throwing must not stop a run of eight million. The row is rolled back to its [savepoint](/safety/transactions#per-row-savepoints), recorded, and the run carries on.

Each failure lands in `backfill_run_errors` with the record id, exception class, message, trace and attempt count. `backfill:status` shows the ten most recent; the [dashboard](/features/dashboard) shows more with a retry button.

The cursor still advances past a failed row. That is intentional — a row that fails deterministically would otherwise wedge the run forever, retrying the same row until someone notices.

Once the cause is fixed:

```bash
php artisan backfill:retry-failed user-slugs
php artisan backfill:retry-failed user-slugs --limit=100
php artisan backfill:retry-failed user-slugs --run=14
```

That re-processes **only** the recorded failures. Re-running the whole backfill would also work for a self-excluding collection, but it means walking eight million rows to reach the two hundred that matter.

Rows that succeed are marked resolved and the run's counters are corrected. Rows that fail again have their attempt count incremented and the new exception recorded. A row that has since been deleted is marked resolved, because there is nothing left to retry.

## A busy database

Deadlocks, lock-wait timeouts and dropped connections are not bugs. The batch rolled back cleanly, and trying again usually works.

The runner retries the batch with exponential backoff:

```php
'retry' => [
    'max_batch_retries' => 3,
    'base_delay_ms' => 250,   // 250ms, 500ms, 1000ms
],
```

What counts as transient is decided by SQLSTATE and driver code, not by guesswork:

| Code | Meaning |
| --- | --- |
| `40001` | Serialization failure |
| `40P01` | Deadlock detected (PostgreSQL) |
| `55P03` | Lock not available (PostgreSQL) |
| `57014` | Query cancelled — statement timeout (PostgreSQL) |
| `08000` / `08003` / `08006` | Connection failures |
| MySQL 1205 | Lock wait timeout exceeded |
| MySQL 1213 | Deadlock found |
| MySQL 2006 / 2013 | Server gone away / lost connection |

Everything else fails the run immediately. A missing column will fail identically forever; retrying it three times just holds locks for longer on the way to the same error.

::: tip One subtlety that caused a real bug
A rolled-back attempt leaves the in-memory counters ahead of what the database holds, because the model was updated before the rollback. The runner calls `$run->refresh()` before retrying — without it, the next persist counts the same rows twice. There is a test named exactly that.
:::

## A systemic problem

A few bad rows are normal. Most rows failing means a bad assumption — a column that does not exist, an API that is down, a relationship that is null more often than you thought.

The circuit breaker pauses the run rather than burning through eight million rows recording the same error:

```php
'circuit_breaker' => [
    'enabled' => true,
    'max_failure_rate' => 0.25,
    'min_sample' => 50,
],
```

Two things make it useful rather than annoying.

**It waits for a meaningful sample.** A two-row batch with one bad row is a 50% failure rate and means nothing. Nothing is judged until `min_sample` rows have been attempted.

**It counts the current session only.** This one is worth dwelling on, because getting it wrong makes the feature actively harmful.

If the rate were measured over the run's lifetime, a run that tripped at 50 failures, got fixed, and was resumed would immediately re-trip on those same historical failures. The first batch after the fix would compute 50 failures against 10 successes and pause again. The run could **never finish**, no matter how thoroughly you fixed the cause.

Counting per session means a resumed run is judged on what happens now. The paused run stays resumable, and once the cause is fixed it runs to completion.

When it trips, the run is paused with an explanation:

```
Circuit breaker tripped: 50 of 50 rows failed in this session (100%, limit 25%).
That rate usually means something systemic rather than bad rows — check
`backfill:status user-slugs`, fix the cause, then resume.
```

A circuit-breaker pause also stops [queue mode](/features/queue) from chaining another job, since the next job would only trip it again.

## Statement and lock timeouts

A batch blocked behind another transaction holds its own locks for as long as it waits. Bound it:

```php
'timeouts' => [
    'statement' => 30000,  // ms
    'lock' => 5000,        // ms
],
```

The blocked batch then fails fast, gets classified as transient, and is retried — instead of quietly becoming the reason production is down.

Applied per engine:

| Engine | Statement | Lock |
| --- | --- | --- |
| MySQL / MariaDB | `max_execution_time` | `innodb_lock_wait_timeout` |
| PostgreSQL | `statement_timeout` | `lock_timeout` |
| SQLite | — | `PRAGMA busy_timeout` |

::: warning MySQL only times out reads
`max_execution_time` constrains `SELECT` statements only. A long `UPDATE` is not affected by it, so on MySQL the **lock timeout** is what actually protects you from a blocked write.
:::

Settings are reset when the run ends, because connections are pooled and a leftover timeout would apply to unrelated work. A server that refuses the setting — a managed database with restricted grants — is ignored rather than allowed to stop the backfill.
