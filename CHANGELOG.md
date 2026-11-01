# Changelog

All notable changes to this package. The same notes, with working cross-links, are in the [documentation](https://kstmostofa.github.io/laravel-backfill/changelog).

## v1.2.0

Unreleased.

- **`backfill:pending`** lists, runs or queues every backfill that has not finished, the way `migrate` handles migrations. Add `backfill:pending --queue --force` to a deploy script and new backfills run themselves. Paused runs resume from their cursor, finished ones are skipped. See [running after a deploy](https://kstmostofa.github.io/laravel-backfill/guide/running#running-after-a-deploy).
- **`$manual`** keeps a backfill out of `backfill:pending`. Operator-panel backfills and ones with a required parameter are left out automatically.

## v1.1.0

Released 2026-11-01. Fixes for queue mode under real workers, a tighter row-ceiling guard, and static analysis in CI.

**Queue mode**

- **Queued slices are time-boxed.** A slice now ends after `batches_per_job` batches or `max_seconds_per_job` seconds, whichever comes first, so a slow `process()` can no longer outlive the worker timeout. The new `time_limit` stop code chains like `max_batches`.
- **Jobs carry their own timeout.** `RunBackfillJob` sets `$timeout` from `backfill.queue.timeout` and turns on `failOnTimeout`.
- **A killed job is reported, not lost.** Its `failed()` hook marks the run `interrupted`, releases the lock, and fires `BackfillFailed`, so notifications go out. Before, the run sat at `running` until its heartbeat went stale and nobody was told.
- **The worker's own shutdown handling is preserved.** The runner used to replace `queue:work`'s `SIGTERM` handler and then reset it to the default. The worker never learned it should quit, and after the job the next `SIGTERM` killed it mid-job. The previous handler is now called alongside the runner's and restored afterwards.

**Guards and retries**

- **`--no-count` no longer bypasses the row ceiling.** While `max_rows_without_confirmation` is set, skipping the count is refused without `--force`.
- **`backfill:retry-failed` is tenant-aware.** It takes the same per-tenant lock as the runner, switches into the run's tenant, and re-applies the run's parameters.

**Dashboard**

- **Throughput leaves out paused time.** Runs record active seconds per session, so a run paused overnight is no longer shown at a fraction of its real speed.

**Housekeeping**

- **Laravel 11 is no longer supported.** It stopped receiving security fixes in March 2026, and Composer now refuses to install its releases by default because of the open advisories. Laravel 12 and 13 are supported.
- Requires `laravel/framework` rather than three `illuminate/*` components. The package always used parts only the full framework ships.
- PHPStan (Larastan, level 5) and Pint run in CI.

## v1.0.0

The first tagged release. It bundles four milestones, listed below in the order they were built.

### v0.4

The operator panel, ledger mode, per-tenant cursors, and the Pulse card.

- **[Operator panel](https://kstmostofa.github.io/laravel-backfill/features/operator-panel)** — mark a backfill `$operatorRunnable`, declare its inputs, and support staff can run it from a browser. Own route, own gate, validated inputs, progress in plain words.
- **[Parameters](https://kstmostofa.github.io/laravel-backfill/reference/parameters)** — `ids`, `text`, `textarea`, `number`, `boolean` and `select`, with `required`, `min`, `max`, `default`, `help` and `placeholder`. Recorded on the run and re-applied on resume; resuming with different parameters is refused.
- **[Ledger mode](https://kstmostofa.github.io/laravel-backfill/advanced/side-effects)** — claim before, confirm after, for work the database cannot roll back. Unconfirmed claims are surfaced, never retried automatically. Declaring side effects without a ledger logs a loud warning.
- **[Multi-tenancy](https://kstmostofa.github.io/laravel-backfill/advanced/multi-tenancy)** — a cursor, run row and lock per tenant, so one tenant crashing never rewinds another and tenants can run side by side.
- **[Pulse card](https://kstmostofa.github.io/laravel-backfill/features/pulse)** — in-flight and troubled runs, failed first, deliberately outside Pulse's period filter.
- `--param` and `--tenant` on `backfill:run`.

### v0.3

The dashboard, queue mode, events and notifications.

- **[Livewire dashboard](https://kstmostofa.github.io/laravel-backfill/features/dashboard)** — live progress, throughput, cursor, batch-duration sparkline, failed rows with retry. Actions are queued, not run in the request. Closed by default outside local.
- **[Queue mode](https://kstmostofa.github.io/laravel-backfill/features/queue)** — `--queue` dispatches a job that runs a slice and chains the next. Short jobs mean a deploy costs one batch. The chain stops on a pause nobody asked for.
- **[Eight lifecycle events](https://kstmostofa.github.io/laravel-backfill/features/events)** with a machine-readable `StopReason` on every pause.
- **[Notifications](https://kstmostofa.github.io/laravel-backfill/features/notifications)** on completion, failure and automatic pause. An operator pausing on purpose is never notified.
- Finished runs prunable via `model:prune`.

### v0.2

Dry run, throttling, retries, the circuit breaker and production guards.

- **[Dry run](https://kstmostofa.github.io/laravel-backfill/features/dry-run)** — scope, index check, duration estimate, and real before/after diffs from rows processed inside a rolled-back transaction. Mail, notifications, jobs and HTTP intercepted; events recorded but not suppressed.
- **[Adaptive throttling](https://kstmostofa.github.io/laravel-backfill/safety/throttling)** on replication lag, with a rolling-median batch-duration fallback.
- **[Transient-failure retries](https://kstmostofa.github.io/laravel-backfill/safety/failures#a-busy-database)** with exponential backoff; everything else fails immediately.
- **[Circuit breaker](https://kstmostofa.github.io/laravel-backfill/safety/failures#a-systemic-problem)** that auto-pauses on a sustained failure rate, counted per session so a fixed run can finish.
- **[Statement and lock timeouts](https://kstmostofa.github.io/laravel-backfill/safety/failures#statement-and-lock-timeouts)** per engine.
- **[Production guards](https://kstmostofa.github.io/laravel-backfill/safety/guards)** — row ceiling, deploy freeze windows.
- `backfill:retry-failed`, and an optional per-batch audit trail.

### v0.1

The MVP.

- `Backfill` class, `make:backfill`, and discovery.
- **[Keyset runner](https://kstmostofa.github.io/laravel-backfill/safety/keyset-pagination)** with cursor persistence — never `OFFSET`.
- **[Per-row error isolation](https://kstmostofa.github.io/laravel-backfill/safety/transactions)** with savepoints.
- `run`, `status`, `list`, `pause`, `resume`, `cancel`.
- The run lock, graceful `SIGTERM`/`SIGINT` shutdown, and the migration guard.
- The [chaos test](https://kstmostofa.github.io/laravel-backfill/safety/invariant#the-test-behind-the-claim): a real `SIGKILL` mid-batch, asserting the resume matches an uninterrupted run.
