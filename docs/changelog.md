# Changelog

## v0.4

The operator panel, ledger mode, per-tenant cursors, and the Pulse card.

- **[Operator panel](/features/operator-panel)** — mark a backfill `$operatorRunnable`, declare its inputs, and support staff can run it from a browser. Own route, own gate, validated inputs, progress in plain words.
- **[Parameters](/reference/parameters)** — `ids`, `text`, `textarea`, `number`, `boolean` and `select`, with `required`, `min`, `max`, `default`, `help` and `placeholder`. Recorded on the run and re-applied on resume; resuming with different parameters is refused.
- **[Ledger mode](/advanced/side-effects)** — claim before, confirm after, for work the database cannot roll back. Unconfirmed claims are surfaced, never retried automatically. Declaring side effects without a ledger logs a loud warning.
- **[Multi-tenancy](/advanced/multi-tenancy)** — a cursor, run row and lock per tenant, so one tenant crashing never rewinds another and tenants can run side by side.
- **[Pulse card](/features/pulse)** — in-flight and troubled runs, failed first, deliberately outside Pulse's period filter.
- `--param` and `--tenant` on `backfill:run`.

## v0.3

The dashboard, queue mode, events and notifications.

- **[Livewire dashboard](/features/dashboard)** — live progress, throughput, cursor, batch-duration sparkline, failed rows with retry. Actions are queued, not run in the request. Closed by default outside local.
- **[Queue mode](/features/queue)** — `--queue` dispatches a job that runs a slice and chains the next. Short jobs mean a deploy costs one batch. The chain stops on a pause nobody asked for.
- **[Eight lifecycle events](/features/events)** with a machine-readable `StopReason` on every pause.
- **[Notifications](/features/notifications)** on completion, failure and automatic pause. An operator pausing on purpose is never notified.
- Finished runs prunable via `model:prune`.

## v0.2

Dry run, throttling, retries, the circuit breaker and production guards.

- **[Dry run](/features/dry-run)** — scope, index check, duration estimate, and real before/after diffs from rows processed inside a rolled-back transaction. Mail, notifications, jobs and HTTP intercepted; events recorded but not suppressed.
- **[Adaptive throttling](/safety/throttling)** on replication lag, with a rolling-median batch-duration fallback.
- **[Transient-failure retries](/safety/failures#a-busy-database)** with exponential backoff; everything else fails immediately.
- **[Circuit breaker](/safety/failures#a-systemic-problem)** that auto-pauses on a sustained failure rate, counted per session so a fixed run can finish.
- **[Statement and lock timeouts](/safety/failures#statement-and-lock-timeouts)** per engine.
- **[Production guards](/safety/guards)** — row ceiling, deploy freeze windows.
- `backfill:retry-failed`, and an optional per-batch audit trail.

## v0.1

The MVP.

- `Backfill` class, `make:backfill`, and discovery.
- **[Keyset runner](/safety/keyset-pagination)** with cursor persistence — never `OFFSET`.
- **[Per-row error isolation](/safety/transactions)** with savepoints.
- `run`, `status`, `list`, `pause`, `resume`, `cancel`.
- The run lock, graceful `SIGTERM`/`SIGINT` shutdown, and the migration guard.
- The [chaos test](/safety/invariant#the-test-behind-the-claim): a real `SIGKILL` mid-batch, asserting the resume matches an uninterrupted run.
