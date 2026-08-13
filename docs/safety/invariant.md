# The invariant

> **A backfill can be killed at any instant, restarted, and must arrive at the same end state — no duplicated side effects, no skipped rows.**

Every design decision in this package is downstream of that sentence. When two approaches are otherwise equally good, the one that keeps this true wins.

## What follows from it

**The cursor is written after the work, in the same transaction.** Each batch is one short transaction containing the row changes, the error records, and the cursor advance. They commit together or not at all. A cursor can never claim work that was rolled back, and work can never be committed without the cursor that records it. The worst case on a crash is that one batch is redone — which is safe, because `collection()` is self-excluding.

**Per-row savepoints.** One bad row must not take down a batch of a thousand. Each row runs inside its own savepoint so a failure rolls back that row alone. On PostgreSQL this is not a nicety: without it, the first failed statement aborts the surrounding transaction and every subsequent statement is rejected — including the `INSERT` that records the error. [More on transactions](/safety/transactions).

**Keyset pagination only.** `OFFSET` silently skips rows when the collection shrinks underneath it. `WHERE id > ? ORDER BY id` cannot. This is not configurable. [More on keyset pagination](/safety/keyset-pagination).

**A unique index is the run lock.** Not a cache key, which can be lost when the cache is flushed. A row in `backfill_locks` with a unique constraint gives identical mutual exclusion on MySQL, PostgreSQL and SQLite.

**Ledger mode fails toward "missed a row".** For work the database cannot roll back, there is no atomic step spanning "send the email" and "record that we sent it". The invariant names both halves, but they cannot both hold, so the package picks: a row is claimed *before* the work, so a crash in between leaves it unprocessed rather than sent twice. Sending nothing is recoverable; sending twice is not. [More on side effects](/advanced/side-effects).

## The test behind the claim

A promise like this is worth exactly as much as the test behind it, so the test does not simulate a crash.

It forks. The child sends **itself a real `SIGKILL` from inside a batch transaction** — a signal that cannot be caught, with no destructors, no shutdown handlers, and no `finally`. It is precisely what the OOM killer or a `kill -9` during a deploy does to a worker.

The parent then waits, confirms the child died by `SIGKILL`, inspects the wreckage, resumes, and asserts the final state is **byte-for-byte identical** to an uninterrupted control run — with every row's process counter at exactly 1.

```php
$pid = pcntl_fork();

if ($pid === 0) {
    DB::reconnect();
    runBackfill(BackfillThatSelfDestructs::class);  // kills itself in batch 2
    posix_kill(getmypid(), SIGKILL);
}

pcntl_waitpid($pid, $status);

expect(pcntl_wtermsig($status))->toBe(SIGKILL);

// The run still claims to be running — nothing got the chance to say otherwise.
// The cursor sits at the end of the last batch that actually committed.
expect($crashed->status)->toBe(RunStatus::Running)
    ->and($crashed->cursor)->toBe($endOfFirstBatch);

// The batch in flight rolled back whole: no half-done batch.
expect(User::whereNotNull('slug')->count())->toBe(2);

$resumed = runBackfill(BackfillThatSelfDestructs::class);

expect(userStateByName())->toBe($expected);            // identical end state
expect(User::where('process_count', 1)->count())->toBe(9);  // exactly once
```

On MySQL and PostgreSQL this is stronger than it looks. Killing the process drops its connection, and the **server** rolls back the in-flight transaction on its own. The resumed run has to agree with a rollback it never observed happening.

## Proving the test has teeth

A passing test proves nothing if it would pass regardless. So each safety guarantee has a matching check that it is load-bearing.

Flip `useTransactions` to `false` on the chaos fixture and the test fails immediately: the kill leaves two rows written while the cursor still reads zero, and the resume double-applies them. That is the exact corruption the per-batch transaction prevents.

The savepoint check is more interesting, and it caught a real gap in this suite. Removing the per-row savepoint and re-running gives:

| Engine | Without per-row savepoints |
| --- | --- |
| SQLite | passes — a failed statement does not poison the transaction |
| MySQL 8.4 | passes — same |
| PostgreSQL 18 | **fails** — `SQLSTATE[25P02]: current transaction is aborted` |

The catch is that this only shows up when a row fails with a *database* error. A fixture that throws a plain PHP exception passes with or without savepoints, because PostgreSQL never sees a failed statement. The original test did exactly that and was therefore proving nothing; the suite now carries a fixture that fails rows against a real unique index.

## Where the invariant does not hold

Three honest exceptions, each documented where it applies:

- **Rows inserted behind the cursor** are not picked up by that run. Keyset pagination walks forwards. Run it again afterwards and a self-excluding collection catches them.
- **Ledger mode can skip a row** rather than risk a duplicate, as described above. `backfill:status` reports the ones in doubt.
- **`hydrateModels = false`** trades per-row isolation for speed. A failure fails the whole batch rather than one row.
