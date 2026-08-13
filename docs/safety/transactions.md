# Transactions and savepoints

Two layers, doing two different jobs.

## The batch transaction

Every batch is one short transaction containing:

1. The row changes made by `process()`
2. The error records for any rows that failed
3. The cursor advance and the counters

They commit together or not at all. That single fact is what makes the package resumable:

- A committed cursor always has the work behind it.
- Work that was rolled back leaves no cursor claiming it.
- A crash mid-batch loses the whole batch, which is then redone — safe, because the collection is self-excluding.

**Short** matters as much as **transactional**. One transaction for the whole run would hold locks for hours and hand the database an undo log it cannot truncate. One per batch bounds lock duration to a batch.

::: danger Do not put the cursor first
Writing the cursor before the work would mean a crash between the two leaves rows permanently skipped — the cursor says done, the data says otherwise. The cursor is always written last, inside the same transaction.
:::

## Per-row savepoints

Inside the batch transaction, each row gets its own savepoint. Laravel gives you this for free by nesting `DB::transaction()`:

```php
protected function processRow(Backfill $backfill, $record): void
{
    if ($backfill->useTransactions) {
        // Nested — issues SAVEPOINT, and rolls back to it on failure.
        $this->connection()->transaction(fn () => $backfill->process($record));

        return;
    }

    $backfill->process($record);
}
```

A row that throws rolls back to its savepoint. The surrounding transaction survives, the other rows in the batch keep their work, and the error record is written.

## Why savepoints are not optional on PostgreSQL

On MySQL and SQLite, a failed statement is just a failed statement — the transaction carries on. On PostgreSQL, a failed statement puts the entire transaction into an **aborted** state where every subsequent statement is rejected until rollback.

Without the savepoint, one bad row would take:

- every other row in the batch,
- the `INSERT` that records why the row failed,
- and the cursor advance.

So the run would lose a thousand rows of work *and* the only evidence of what went wrong.

Removing the savepoint and re-running the suite makes this concrete:

| Engine | Without per-row savepoints |
| --- | --- |
| SQLite | passes |
| MySQL 8.4 | passes |
| PostgreSQL 18 | **fails** — `SQLSTATE[25P02]: current transaction is aborted, commands ignored until end of transaction block` |

The rejected statement in that failure is the `INSERT INTO backfill_run_errors`.

### The subtlety worth knowing

This only surfaces when a row fails with a **database** error. A `process()` that throws a plain PHP exception before touching the database never puts PostgreSQL into the aborted state, so such a test passes whether or not savepoints exist.

The original test in this package did exactly that, and was proving nothing. The suite now carries a fixture that fails rows against a real unique index — the only version of the test that means anything:

```php
class BackfillWithDatabaseError extends Backfill
{
    public function process($record): void
    {
        // Every row wants the same label, but the column is unique —
        // the first row succeeds and the rest collide.
        $record->forceFill(['label' => 'duplicate'])->save();
    }
}
```

If you write your own tests around row failures, make at least one of them fail at the database level for the same reason.

## Turning transactions off

```php
public bool $useTransactions = false;
```

This is almost always wrong. It breaks the guarantee that the cursor and the data agree, and it disables per-row savepoints along with it.

The one case where it is defensible is a `process()` whose work the database cannot roll back anyway — but that case is better served by [ledger mode](/advanced/side-effects), which is built for exactly it.
