# Laravel Backfill

Safe, resumable, one-off data backfills for Laravel.

Laravel gives you schema migrations for structure and queues for background work, but nothing for **data backfills** — filling a new column across 8M rows, recalculating values that were computed wrong, migrating a boolean into a roles table. So every team hand-rolls the same `chunk()` loop, and that loop is wrong in ways that only show up in production.

This package is built around one invariant:

> **A backfill can be killed at any instant, restarted, and must arrive at the same end state — no duplicated side effects, no skipped rows.**

Every design decision in here is downstream of that sentence.

## Installation

```bash
composer require kstmostofa/laravel-backfill
php artisan migrate
```

The migrations ship with the package and load automatically. Publish them if you want to edit them:

```bash
php artisan vendor:publish --tag=backfill-migrations
php artisan vendor:publish --tag=backfill-config
```

Requires PHP 8.2+ and Laravel 11, 12 or 13. Tested against MySQL 8.4, PostgreSQL 18 and SQLite.

## Writing a backfill

```bash
php artisan make:backfill BackfillUserSlugs
```

```php
namespace App\Backfills;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;

class BackfillUserSlugs extends Backfill
{
    public int $batchSize = 1000;

    public int $sleepMs = 100;

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function process($record): void
    {
        $record->update(['slug' => Str::slug($record->name)]);
    }
}
```

Make `collection()` **self-excluding** — once a row has been processed it should no longer match the query. That single property is what makes a backfill idempotent for free: re-running, resuming, or redoing a rolled-back batch can never double-apply.

## Running it

```bash
php artisan backfill:list                       # discovered backfills + last run status
php artisan backfill:run user-slugs --dry-run   # scope, index check, real diffs, zero writes
php artisan backfill:run user-slugs             # resumable execution
php artisan backfill:status user-slugs          # progress, throughput, failed rows
php artisan backfill:pause user-slugs           # stop cleanly after the current batch
php artisan backfill:resume user-slugs          # continue from the committed cursor
php artisan backfill:cancel user-slugs          # stop for good; will not resume
php artisan backfill:retry-failed user-slugs    # re-process only the rows that failed
```

Useful flags on `backfill:run`: `--dry-run` and `--samples=`, `--queue` and `--batches-per-job=`, `--fresh` (ignore a resumable run), `--batch-size=`, `--sleep=`, `--max-batches=` (stop cleanly after N batches), `--no-count` (skip the up-front estimate on tables too big to count), `--force` (skip the production guards and confirmation).

## Running on the queue

```bash
php artisan backfill:run user-slugs --queue
```

This dispatches a job that runs 25 batches and then queues the next one, rather than a single long-lived job. Short jobs are the whole point: a worker restart mid-deploy costs at most one batch, and the next job resumes from the committed cursor. Configure the connection, queue and slice size under `backfill.queue`.

The chain stops on its own when the backfill finishes — and, importantly, it also stops when the run pauses for a reason nobody asked for. A circuit-breaker or throttle pause would only trip again on the next job, so those need a human to look first. That distinction is what the `stop_code` on each run records.

## The dashboard

An optional Livewire dashboard for watching and driving runs: live progress and throughput, cursor, a batch-duration sparkline, and the failed rows with a retry button. Actions taken here are queued, not run in the web request.

```bash
composer require livewire/livewire
```

```php
// config/backfill.php
'dashboard' => ['enabled' => true, 'path' => 'backfills', 'middleware' => ['web']],
```

It is **closed by default** outside local development, because it can start and cancel data changes over production tables. Open it deliberately, in a service provider:

```php
use Kstmostofa\Backfill\Dashboard\Dashboard;

Dashboard::auth(fn ($request) => $request->user()?->isAdmin() === true);
```

The package works fine without Livewire — the dashboard simply does not register.

## The operator panel

The reason teams keep this installed. A developer marks a backfill available and declares what it needs; support staff then run it themselves, from a browser, with no shell and no developer.

```php
class BackfillOrderRefunds extends Backfill
{
    public bool $operatorRunnable = true;

    public function description(): string
    {
        return 'Re-issue refund receipts';
    }

    public function parameters(): array
    {
        return [
            Parameter::ids('order_ids', 'Order IDs')
                ->required()
                ->max(50_000)
                ->help('Paste the ids from the spreadsheet.'),

            Parameter::select('tone', ['formal' => 'Formal', 'friendly' => 'Friendly']),
        ];
    }

    public function collection(): Builder
    {
        return Order::query()
            ->whereNull('receipt_sent_at')
            ->whereIn('id', $this->parameter('order_ids', []));
    }
}
```

The panel lives at its own route with its own gate, because the people who should be pasting order ids into a form are rarely the people who should be able to cancel a run half way through:

```php
Dashboard::operatorAuth(fn ($request) => $request->user()?->isSupport() === true);
```

Only backfills marked `$operatorRunnable` appear, only their declared parameters can be set, and every input is validated before a job is queued — a pasted list gets split however the spreadsheet formatted it (commas, newlines, semicolons), de-duplicated, and checked against its ceiling. Progress is described in plain words rather than cursors and batch counts.

Parameters are recorded on the run and re-applied on resume. Trying to resume a paused run with *different* parameters is refused: half the rows processed under one set of inputs and half under another is not a state anyone wants to reason about later.

The same parameters work from the command line:

```bash
php artisan backfill:run order-refunds --param=order_ids=1,2,3 --param=tone=friendly
```

## Backfills with external side effects

The per-batch transaction makes a redo safe for database writes — a rolled-back batch never happened. An email does not roll back. For that case, turn on the ledger:

```php
public bool $ledger = true;
public bool $externalSideEffects = true;
```

A row is **claimed** in its own committed transaction before `process()` runs, and **confirmed** afterwards. That ordering is a deliberate trade: a crash between the claim and the work leaves a row unprocessed rather than an email sent twice. Sending nothing is recoverable; sending twice is not.

Rows left claimed-but-unconfirmed are exactly the ones nobody can be sure about, so they are never retried automatically. `backfill:status` reports how many there are so a human can decide.

Setting `$externalSideEffects = true` without a ledger logs a loud warning at the start of every run. That combination — work that escapes the database, with nothing stopping a redo — is the one where a resume re-sends four million emails.

## Multi-tenant backfills

Each tenant gets its own cursor, its own run row and its own lock, so one tenant crashing or pausing never rewinds another.

```php
public function tenants(): ?iterable
{
    return Tenant::query()->pluck('id');
}

public function useTenant(string|int $tenant): void
{
    Tenant::find($tenant)->makeCurrent();
}
```

`backfill:run` then walks every tenant in turn and reports each, or `--tenant=acme` runs just one. Because the locks are independent, separate workers can run different tenants side by side.

## Pulse card

With [Laravel Pulse](https://pulse.laravel.com) installed, add the card to your dashboard:

```blade
<livewire:backfill-pulse-card cols="6" />
```

It lists runs that are in flight or want attention — failed first, then interrupted, paused, and running — and stays out of Pulse's period filter on purpose: a backfill that has been paused for three days is exactly what you want to see.

## Events

Every run emits `BackfillStarted`, `BackfillResumed`, `BatchProcessed`, `RowFailed`, `BackfillPaused`, `BackfillCompleted`, `BackfillFailed` and `ThrottleEngaged` under `Kstmostofa\Backfill\Events`.

`BackfillPaused` carries a `StopReason` and a `wasAutomatic()` helper, so you can tell a circuit-breaker or throttle pause from an operator pressing pause. Note that `BatchProcessed` fires on every batch — thousands of times on a large run — so keep those listeners cheap and do not queue them.

Notifications are built on the same events and are off by default:

```php
'notifications' => [
    'enabled' => true,
    'on' => ['completed', 'failed', 'paused'],
    'mail' => 'ops@example.com',
    'slack_webhook' => 'https://hooks.slack.com/services/...',
],
```

Only three moments are worth interrupting someone: a run finished, failed, or paused itself. An operator pausing a run on purpose is never notified — they already know. A mail server that is down cannot turn a completed run into a failed one; delivery errors are swallowed deliberately.

Slack posts straight to an incoming webhook rather than going through a notification channel, so Slack support does not drag in another package.

## The dry run

`--dry-run` is the command to reach for before any real run. It answers the four questions worth asking, and writes nothing:

```
  Dry run: user-slugs — nothing was written.

  Rows matching ........................................... 8,412,663
  Batch size .................................................. 1,000
  Index ....................... indexed — Walks index PRIMARY (type=range), no sort.
  Estimated duration ........................................... ~4.2h

  Sampled 5 rows, rolled back:

  +------+----------------------------------------------------+
  | Row  | What would change                                  |
  +------+----------------------------------------------------+
  | 1041 | slug: null → ada-lovelace, updated_at: … → …       |
  | 1042 | slug: null → alan-turing, updated_at: … → …        |
  +------+----------------------------------------------------+

  Side effects intercepted (these would have escaped in a real run):
  mail ................. 5 across 5 rows — roughly 8,412,663 in full
```

The diffs are real: those five rows are genuinely processed, inside a transaction that is then rolled back. A dry run that only prints the query tells you nothing about whether `process()` does what you think it does.

Everything with no rollback is intercepted before it happens — mail, notifications, queued and dispatched jobs, and HTTP calls made through Laravel's client. Application events are *recorded but not suppressed*, deliberately: faking them would stop model observers running, and the diff would then show something a real run would never produce.

Two limits worth knowing. HTTP interception only covers Laravel's HTTP client, so a raw cURL call in `process()` still escapes. And the diff covers the sampled rows themselves — if `process()` also writes to another table, the rollback still protects you, but the change will not appear in the table above.

## What it actually does for you

**Keyset pagination, never OFFSET.** Laravel's `chunk()` pages with `LIMIT/OFFSET`. When your transform makes rows leave the result set — exactly what a self-excluding backfill does — the result set shrinks under the cursor and every offset jump skips as many rows as the last batch removed. Roughly half your table is silently missed. This package only ever does `WHERE id > ? ORDER BY id LIMIT ?`, and it is not configurable. Any ordering your `collection()` came with is stripped, because keyset pagination is only correct when the sort matches the cursor column.

**The cursor is written after the work, in the same transaction.** Each batch is one short transaction containing the row changes, the error records, and the cursor advance. They commit together or not at all, so the cursor can never claim work that was rolled back. Worst case on a crash is that one batch is redone — which is safe, because `collection()` is self-excluding.

**One bad row cannot kill the run.** Each row is processed inside its own savepoint. A row that throws is rolled back on its own, recorded in `backfill_run_errors` with its id, exception class, message and trace, and the run carries on. Without the savepoint, one failure would poison the surrounding transaction on PostgreSQL and take the whole batch — including the error records — with it.

**It refuses to run inside a migration.** Migrations run synchronously during deploy, often inside a transaction. A multi-million row change there blocks the pipeline and risks a statement timeout.

**Graceful shutdown.** SIGTERM and SIGINT are trapped: the batch in flight finishes, commits its cursor, and the run is marked `paused` so `backfill:resume` picks it straight back up.

**No double-runs.** A row in `backfill_locks` is the run lock, acquired with an insert against a unique index — identical guarantees on MySQL, PostgreSQL and SQLite, unlike a partial unique index, which MySQL does not have. A second attempt is told who holds it and since when. A lock abandoned by a killed process goes stale with its heartbeat and is taken over automatically.

**A busy database is retried; a bug is not.** Deadlocks, lock timeouts and dropped connections leave the batch rolled back cleanly, so the batch is retried with exponential backoff. Everything else fails the run immediately — a missing column will fail identically forever, and retrying it only holds locks longer to reach the same error.

**Statement and lock timeouts.** Set `backfill.timeouts` and the runner bounds how long any single statement, or any wait for a lock, may take. A blocked batch then fails fast and is retried instead of holding its own locks while it waits. (On MySQL, `max_execution_time` only constrains reads, so the lock timeout is what protects a blocked write.)

**A circuit breaker for systemic failure.** A few bad rows are normal. Most rows failing means a bad assumption, and the run auto-pauses rather than burning through eight million rows recording the same error. The rate is only judged once enough rows have been attempted to mean anything, and it counts the current session only — so a run that tripped, got fixed, and was resumed is judged on what happens next, not on the failures that prompted the fix.

**Adaptive throttling.** With `backfill.throttle.enabled`, the runner watches replication lag. Under the soft threshold it runs at full speed; between soft and hard it slows down proportionally and halves the batch; above hard it stops issuing batches until the replicas recover, and pauses the run if they have not within `lag_timeout`. It also backs off when a batch suddenly takes far longer than the rolling median, which catches contention that lag does not show. An unreadable lag signal — a missing `REPLICATION CLIENT` grant, say — counts as healthy, because stalling a backfill over a missing permission is worse than not throttling.

**Production guards.** A run larger than `max_rows_without_confirmation` is refused without `--force`, and so is a run started inside a configured deploy-freeze window. Every run records who started it.

## The chaos test

The claim "you can kill it at any moment" is worth exactly as much as the test behind it, so the test does not simulate a crash. It forks, and the child sends **itself a real `SIGKILL` from inside a batch transaction**. SIGKILL cannot be caught — no destructors, no shutdown handlers, no `finally` — which is precisely what the OOM killer or a `kill -9` during a deploy does to a worker.

The test then asserts the resumed run reaches **byte-for-byte the same end state as an uninterrupted control run**, with every row processed exactly once.

```
   PASS  Tests\Chaos\HardKillTest
  ✓ it resumes after a SIGKILL to the identical end state                1.19s
  ✓ it leaves no work half-applied when killed inside a batch            1.11s

  Tests:    60 passed (172 assertions)
```

On MySQL and PostgreSQL this is a stronger test than it looks: killing the process drops its connection, and the server rolls back the in-flight transaction on its own. The resumed run has to agree with a rollback it never saw happen.

To confirm the test has teeth, flipping `useTransactions` to `false` on the fixture makes it fail immediately — the kill leaves two rows written while the cursor still says zero, and the resume double-applies them. That is the failure this package exists to prevent.

The suite also carries a regression test that reproduces the OFFSET bug directly, asserting that the naive `chunk()`-style walk misses rows while the keyset runner does not.

## Running the tests

The full suite runs on SQLite, MySQL and PostgreSQL. Pick the engine with `BACKFILL_DRIVER`:

```bash
composer test                          # SQLite (default)
BACKFILL_DRIVER=mysql composer test    # MySQL 8+ / MariaDB
BACKFILL_DRIVER=pgsql composer test    # PostgreSQL 13+
```

Connection details come from `BACKFILL_MYSQL_*` and `BACKFILL_PGSQL_*` environment variables; the defaults match a stock local MySQL and PostgreSQL with a `laravel_backfill_test` database. The chaos tests need the `pcntl` and `posix` extensions and skip themselves if either is missing.

Verified green on all three: **245 tests, 554 assertions** against SQLite, MySQL 8.4 and PostgreSQL 18, with the SIGKILL chaos test running for real on each.

### Why the savepoints are not optional

The per-row savepoint is the one guarantee whose necessity is engine-specific, so the suite pins it down rather than asserting it. Removing the savepoint and re-running gives:

| Engine | Without per-row savepoints |
| --- | --- |
| SQLite | passes — a failed statement does not poison the transaction |
| MySQL 8.4 | passes — same |
| PostgreSQL 18 | **fails** — `SQLSTATE[25P02]: current transaction is aborted, commands ignored until end of transaction block` |

On PostgreSQL the first row to violate a constraint aborts the whole transaction, and every statement after it is rejected — including the `INSERT` that records the error and the `UPDATE` that advances the cursor. One bad row would silently cost you the entire batch.

Worth knowing: this only shows up when a row fails with a *database* error. A fixture that throws a plain PHP exception passes with or without savepoints, because PostgreSQL never sees a failed statement. The suite therefore carries a fixture that fails rows against a real unique index, which is the only version of the test that proves anything.

## Testing your own backfills

```php
use Kstmostofa\Backfill\Testing\InteractsWithBackfills;

uses(InteractsWithBackfills::class);

it('slugs every user', function () {
    User::factory()->count(5)->create(['slug' => null]);

    $run = $this->runBackfill(BackfillUserSlugs::class);

    expect($run->processed_count)->toBe(5)
        ->and(User::whereNull('slug')->count())->toBe(0);
});
```

The helper defaults to a batch size of 2 so your tests exercise the real pagination path, rather than a single batch that would hide ordering and cursor bugs. Pass `['batchSize' => n]` to override it, along with any other run option (`maxBatches`, `fresh`, `withoutEstimate`, `force`).

## Configuration

`config/backfill.php`:

| Key | Default | What it does |
| --- | --- | --- |
| `path` | `app_path('Backfills')` | Directory scanned for backfill classes |
| `connection` | `null` | Connection for the bookkeeping tables. Keep it the same as the data being backfilled so the cursor commits in the same transaction as the work |
| `batch_size` | `1000` | Default rows per batch; the class property wins |
| `sleep_ms` | `0` | Default pause between batches; the class property wins |
| `stale_after` | `120` | Seconds without a heartbeat before a run counts as crashed and can be resumed |
| `timeouts.statement` | `null` | Milliseconds before a single statement is killed |
| `timeouts.lock` | `null` | Milliseconds to wait for a lock before giving up |
| `retry.max_batch_retries` | `3` | Retries for a batch that failed transiently |
| `retry.base_delay_ms` | `250` | First backoff delay; doubles each retry |
| `circuit_breaker.enabled` | `true` | Auto-pause when failures look systemic |
| `circuit_breaker.max_failure_rate` | `0.25` | Session failure rate that trips it |
| `circuit_breaker.min_sample` | `50` | Rows attempted before the rate is judged |
| `throttle.enabled` | `false` | Watch replication lag and back off |
| `throttle.connection` | `null` | Replica connection to measure lag on |
| `throttle.lag_soft` / `lag_hard` | `5` / `30` | Seconds of lag to slow down at, then stop at |
| `throttle.lag_timeout` | `600` | Seconds to wait for recovery before pausing |
| `throttle.min_batch_size` | `50` | Floor the throttle will not shrink past |
| `throttle.slow_batch_multiplier` | `5` | Back off when a batch exceeds this × the median |
| `dry_run.samples` | `5` | Rows processed and rolled back during `--dry-run` |
| `guards.max_rows_without_confirmation` | `1000000` | Refuse a bigger run without `--force` |
| `guards.deploy_freeze` | disabled | Windows during which runs are refused |
| `queue.connection` / `queue.queue` | `null` | Where `--queue` dispatches to |
| `queue.batches_per_job` | `25` | Batches each queued job runs before chaining |
| `notifications.enabled` | `false` | Notify on completion, failure and auto-pause |
| `notifications.mail` | `null` | Address (or array) to email |
| `notifications.slack_webhook` | `null` | Slack incoming webhook URL |
| `dashboard.enabled` | `false` | Register the Livewire dashboard route |
| `dashboard.path` / `middleware` | `backfills` / `['web']` | Where it lives and what guards it |
| `dashboard.operator_path` | `backfills/tasks` | Where the operator panel lives |
| `record_batches` | `false` | Write one audit row per batch |
| `prune_runs_after_days` | `90` | Retention for finished runs, via `model:prune` |

## Backfill API

| Member | Purpose |
| --- | --- |
| `collection(): Builder` | The rows to process. Make it self-excluding |
| `process($record)` | Apply the change to one row |
| `processBatch(Collection $rows)` | Whole-batch fast path, used when `$hydrateModels = false` |
| `guard(): bool` | Return false to refuse to start |
| `beforeRun` / `afterRun` | Called once around the run |
| `beforeBatch` / `afterBatch` | Called around each batch, inside the transaction |
| `onRowFailed($record, $e)` | Called after a row failure is recorded |
| `keyName(): string` | Column to paginate over; defaults to the model key |
| `$batchSize`, `$sleepMs` | Per-class overrides of the config defaults |
| `$useTransactions` | Per-batch transaction wrapping. Leave it on |
| `$hydrateModels` | Set false for the raw query-builder fast path |
| `$withoutModelEvents` | Suppress observers so they do not fire 8M times |
| `$operatorRunnable` | Offer this in the operator panel |
| `parameters(): array` | Inputs an operator supplies; read with `parameter()` |
| `$ledger` | Record processed rows for work that escapes the database |
| `$externalSideEffects` | Declares that `process()` reaches outside the database |
| `tenants(): ?iterable` | Tenant identifiers, each with its own cursor |
| `useTenant($tenant)` | Switch context before that tenant's rows are read |
| `description(): string` | Human-readable name, shown in the operator panel |

Integer, UUID and ULID keys all round-trip: the cursor is stored as a string and cast back based on the model's key type.

## Status: v0.4 — feature complete

Shipped and tested across SQLite, MySQL and PostgreSQL:

- **v0.1** — the class and discovery, the keyset runner, cursor persistence, per-row error isolation, run/status/pause/resume/cancel, the run lock, graceful shutdown
- **v0.2** — dry run with real diffs and side-effect interception, adaptive throttling on replication lag, `backfill:retry-failed`, statement and lock timeouts, transient-failure retries, the circuit breaker, production guards, and the optional per-batch audit trail
- **v0.3** — the Livewire dashboard, `--queue` mode with self-chaining jobs, the eight lifecycle events, notifications, and run pruning
- **v0.4** — the operator panel with declared parameters, ledger mode for external side effects, per-tenant cursors, and the Pulse card

### Known limits

Rows inserted *behind* the cursor after it has passed are not picked up — that is inherent to keyset pagination, and a second run afterwards catches them. `backfill:status` does not yet report how many such rows exist.

The dry run intercepts HTTP only through Laravel's client, so a raw cURL call still escapes, and its diff covers the sampled rows rather than every table `process()` might touch. Dry runs are not recorded as runs, so there is no history of who dry-ran what.

Throttling needs a lag signal it can actually read. On PostgreSQL it works from a primary via `pg_stat_replication` or from a replica directly; on MySQL there is no primary-side equivalent, so point `throttle.connection` at a replica or throttling stays inactive.

Ledger mode picks a side. A crash between claiming a row and finishing it leaves that row unprocessed rather than risking a duplicate, so a ledger-backed backfill can quietly skip rows that a non-ledger one would have retried. `backfill:status` surfaces them; nothing retries them for you.

`Backfill::fake()` with `assertCompleted` / `assertProcessed` / `assertNoFailures` is still not shipped — the facade name would collide with the `Backfill` base class, and that is worth resolving deliberately rather than in a rush. Use the `InteractsWithBackfills` trait in the meantime.

## License

MIT.
