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
php artisan backfill:list                  # discovered backfills + last run status
php artisan backfill:run user-slugs        # resumable execution
php artisan backfill:status user-slugs     # progress, throughput, failed rows
php artisan backfill:pause user-slugs      # stop cleanly after the current batch
php artisan backfill:resume user-slugs     # continue from the committed cursor
php artisan backfill:cancel user-slugs     # stop for good; will not resume
```

Useful flags on `backfill:run`: `--fresh` (ignore a resumable run), `--batch-size=`, `--sleep=`, `--max-batches=` (stop cleanly after N batches), `--no-count` (skip the up-front estimate on tables too big to count), `--force` (skip the production confirmation in CI).

## What it actually does for you

**Keyset pagination, never OFFSET.** Laravel's `chunk()` pages with `LIMIT/OFFSET`. When your transform makes rows leave the result set — exactly what a self-excluding backfill does — the result set shrinks under the cursor and every offset jump skips as many rows as the last batch removed. Roughly half your table is silently missed. This package only ever does `WHERE id > ? ORDER BY id LIMIT ?`, and it is not configurable. Any ordering your `collection()` came with is stripped, because keyset pagination is only correct when the sort matches the cursor column.

**The cursor is written after the work, in the same transaction.** Each batch is one short transaction containing the row changes, the error records, and the cursor advance. They commit together or not at all, so the cursor can never claim work that was rolled back. Worst case on a crash is that one batch is redone — which is safe, because `collection()` is self-excluding.

**One bad row cannot kill the run.** Each row is processed inside its own savepoint. A row that throws is rolled back on its own, recorded in `backfill_run_errors` with its id, exception class, message and trace, and the run carries on. Without the savepoint, one failure would poison the surrounding transaction on PostgreSQL and take the whole batch — including the error records — with it.

**It refuses to run inside a migration.** Migrations run synchronously during deploy, often inside a transaction. A multi-million row change there blocks the pipeline and risks a statement timeout.

**Graceful shutdown.** SIGTERM and SIGINT are trapped: the batch in flight finishes, commits its cursor, and the run is marked `paused` so `backfill:resume` picks it straight back up.

**No double-runs.** A row in `backfill_locks` is the run lock, acquired with an insert against a unique index — identical guarantees on MySQL, PostgreSQL and SQLite, unlike a partial unique index, which MySQL does not have. A second attempt is told who holds it and since when. A lock abandoned by a killed process goes stale with its heartbeat and is taken over automatically.

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

Verified green on all three: **60 tests, 172 assertions** against SQLite, MySQL 8.4 and PostgreSQL 18, with the SIGKILL chaos test running for real on each.

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

The helper defaults to a batch size of 2 so your tests exercise the real pagination path, rather than a single batch that would hide ordering and cursor bugs. Pass `['batchSize' => n]` to override it, along with any other run option (`maxBatches`, `fresh`, `withoutEstimate`).

`Backfill::fake()` with `assertCompleted` / `assertProcessed` / `assertNoFailures` is planned but not in v0.1 — the facade name would currently collide with the `Backfill` base class, and that is worth resolving deliberately rather than in a rush.

## Configuration

`config/backfill.php`:

| Key | Default | What it does |
| --- | --- | --- |
| `path` | `app_path('Backfills')` | Directory scanned for backfill classes |
| `connection` | `null` | Connection for the bookkeeping tables. Keep it the same as the data being backfilled so the cursor commits in the same transaction as the work |
| `batch_size` | `1000` | Default rows per batch; the class property wins |
| `sleep_ms` | `0` | Default pause between batches; the class property wins |
| `stale_after` | `120` | Seconds without a heartbeat before a run counts as crashed and can be resumed |

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

Integer, UUID and ULID keys all round-trip: the cursor is stored as a string and cast back based on the model's key type.

## Status: v0.1

This is the MVP. Shipped and tested: the class and discovery, the keyset runner, cursor persistence, per-row error isolation, run/status/pause/resume/cancel, the run lock, and graceful shutdown.

Planned:

- **v0.2** — dry-run with real before/after diffs and side-effect faking, adaptive throttling on replication lag, `backfill:retry-failed`, explicit statement and lock timeouts, production guardrails
- **v0.3** — Livewire dashboard, `--queue` mode, events, notifications
- **v0.4** — operator panel with declared parameters, ledger mode for external side effects, Pulse card, per-tenant cursors

### Known limits in v0.1

Rows inserted *behind* the cursor after it has passed are not picked up — that is inherent to keyset pagination, and a second run afterwards catches them. `backfill:status` does not yet report how many such rows exist.

`--dry-run` is deliberately absent rather than half-built: a dry run that does not fake mail, notifications, queued jobs, events and HTTP is not actually safe, and that work belongs to v0.2.

## License

MIT.
