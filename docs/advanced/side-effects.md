# External side effects

The [per-batch transaction](/safety/transactions) makes a redo safe for database writes: a rolled-back batch never happened, so doing it again is free.

An email does not roll back. Neither does a payment, a webhook, or a row in someone else's system.

## The problem

Consider a backfill that emails four million customers. The collection cannot be self-excluding, because sending an email leaves no trace on the row.

A batch commits, the process is killed, the run resumes from the last committed cursor — and re-sends every email in the batch that was in flight. Do that a few times and you have sent the same customer four copies of the same message.

## Ledger mode

```php
public bool $ledger = true;
public bool $externalSideEffects = true;
```

Every row is recorded in `backfill_ledger`, and rows already there are skipped. A second pass sends nothing.

## The trade it makes

This is the part to understand before relying on it.

There is no atomic step spanning "send the email" and "record that we sent it". They are two systems. Whatever order you choose, a crash between them leaves you wrong in one direction:

- **Record after sending** → a crash between the two re-sends on resume.
- **Record before sending** → a crash between the two skips the row.

The invariant this package is built on names both halves — no duplicated side effects, *and* no skipped rows — and here they cannot both hold. So the package picks, and it picks **no duplicates**:

> A row is **claimed** in its own committed transaction before `process()` runs, and **confirmed** afterwards.

Sending nothing is recoverable — you can find the row and send it. Sending twice is not.

::: warning The claim is committed outside the batch transaction
This is deliberate and it is what makes the whole thing work. If the claim were inside the batch transaction, a rollback would erase the very record that prevents the redo.
:::

## Rows in doubt

A crash between the claim and the confirmation leaves a **claimed but unconfirmed** row. Nobody can tell from the outside whether the email escaped.

Those rows are never retried automatically, and `backfill:status` reports them:

```
213 rows are claimed but unconfirmed — the run stopped part way through them,
so whether the side effect happened is unknown. They will not be retried
automatically.
```

A row whose `process()` **threw** is treated the same way. The failure is recorded as normal, but the claim stays: whether the exception happened before or after the send is not knowable from here, so the runner does not guess.

Inspect them yourself:

```php
use Kstmostofa\Backfill\Runner\Ledger;

$doubtful = app(Ledger::class)->unconfirmed('customer-emails');

// Decided they never went out?
app(Ledger::class)->release('customer-emails', $recordId);
```

`release()` gives the claim back so the row can be processed again. Only call it when you have established the side effect definitely did not happen.

## The loud warning

Declaring external side effects without a ledger logs a warning at the start of **every** run:

```php
public bool $externalSideEffects = true;
public bool $ledger = false;   // nothing protecting it
```

```
Backfill [customer-emails] declares external side effects but has no ledger.
A batch that is retried or resumed after a crash will run process() again for
those rows, re-sending anything it already sent. Set $ledger = true, or make
collection() self-excluding so a processed row stops matching.
```

That combination is the one where a resume re-sends four million emails. The package cannot detect side effects on its own, which is why `$externalSideEffects` exists — declaring it is how you get the warning.

## When you do not need a ledger

If the row itself records that the work happened, you already have a better ledger than this one:

```php
public function collection(): Builder
{
    return Customer::query()->whereNull('welcome_email_sent_at');
}

public function process($record): void
{
    Mail::to($record)->send(new WelcomeEmail);

    $record->update(['welcome_email_sent_at' => now()]);
}
```

This is self-excluding, so a resume skips it naturally — no ledger table, no unconfirmed rows, no extra writes.

The gap is the same one ledger mode has: a crash between the send and the update re-sends that one row. If you can add a column, this is the cheaper approach; ledger mode is for when you cannot touch the schema.

## Cost

Ledger mode adds, per batch, one lookup of which rows are already recorded, and per row, one insert and one update.

Rows the ledger skips are counted in `skipped_count`, so `backfill:status` distinguishes "processed" from "already done" from "failed".

## Idempotency keys beat both

If the system you are calling supports an idempotency key, use it. Sending the row id as the key means a duplicate call is a no-op on their side, which is a stronger guarantee than anything achievable from here. Ledger mode is what you reach for when the other end offers you nothing.
