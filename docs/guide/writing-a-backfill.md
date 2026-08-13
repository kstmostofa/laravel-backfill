# Writing a backfill

```bash
php artisan make:backfill BackfillUserSlugs
```

That writes `app/Backfills/BackfillUserSlugs.php`:

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

Two methods matter. `collection()` says which rows to process; `process()` changes one of them.

## Make `collection()` self-excluding

This is the single most important thing on the page.

A **self-excluding** query is one where a row stops matching once it has been processed. `whereNull('slug')` is self-excluding, because `process()` fills in the slug. `where('created_at', '<', $date)` is not — the row matches just as well the second time.

Self-exclusion buys you idempotency for free:

- Re-running the backfill does nothing to rows already done.
- A batch that rolled back is redone with no double-application.
- A resume after a crash cannot repeat committed work.

When you cannot make it self-excluding — because the work leaves no trace in the row — see [external side effects](/advanced/side-effects).

::: tip Rows added behind the cursor
Keyset pagination walks forwards only. A row inserted with an id *below* the current cursor is not picked up by that run. Run the backfill again afterwards and it catches them, because the collection is self-excluding.
:::

## The name

`BackfillUserSlugs` becomes `user-slugs` on the command line. The leading or trailing `Backfill` is stripped and the rest is kebab-cased, so `UserSlugsBackfill` gives the same name.

Commands accept the short name, the class basename, or the fully qualified class name — whichever you find easier to type.

## Tuning the run

```php
public int $batchSize = 1000;      // rows per batch
public int $sleepMs = 100;         // pause between batches
public bool $useTransactions = true;
public bool $hydrateModels = true;
public bool $withoutModelEvents = false;
```

`batchSize` and `sleepMs` fall back to the config defaults when you leave them off. Everything can be overridden per run with `--batch-size` and `--sleep`.

::: danger Leave `$useTransactions` on
It is what commits the row changes, the error records and the cursor together. Turning it off breaks the guarantee that the cursor can never claim work that was rolled back. Only consider it when `process()` touches something the database cannot roll back anyway — and in that case you probably want [ledger mode](/advanced/side-effects) instead.
:::

## The fast path

Model hydration costs real time across millions of rows. When `process()` is a plain column update, skip it:

```php
public bool $hydrateModels = false;

public function processBatch(Collection $rows): void
{
    DB::table('users')
        ->whereIn('id', $rows->pluck('id'))
        ->update(['status' => 'archived']);
}
```

Rows arrive as `stdClass` from the query builder and `processBatch()` is called once per batch instead of `process()` per row.

The trade: you lose per-row error isolation. A failure in `processBatch()` fails the whole batch rather than recording one bad row and carrying on.

## Silencing observers

An observer that fires 8M times is usually a mistake — activity log entries, search index updates, cache invalidations, all of them multiplied by your row count.

```php
public bool $withoutModelEvents = true;
```

The whole run is wrapped in `Model::withoutEvents()`.

## Hooks

Everything below is optional.

```php
public function guard(): bool
{
    // Refuse to start. Checked before the lock is taken.
    return config('features.slugs_enabled');
}

public function beforeRun(BackfillRun $run): void {}
public function afterRun(BackfillRun $run): void {}

// These two run inside the batch transaction.
public function beforeBatch(Collection $rows, BackfillRun $run): void {}
public function afterBatch(Collection $rows, BackfillRun $run): void {}

public function onRowFailed($record, Throwable $e): void
{
    // The failure is already recorded; this is for your own reporting.
}

public function keyName(): string
{
    // Column to paginate over. Must be unique and sortable.
    return 'id';
}

public function description(): string
{
    // Shown in backfill:list and the operator panel.
    return 'Give every user a slug';
}
```

::: warning `afterBatch()` runs inside the transaction
Throwing from it rolls back the whole batch, cursor included. That is often what you want — it is a good place to assert an invariant — but it does mean the batch is redone or the run fails.
:::

## Keys other than auto-increment integers

UUIDs and ULIDs work without any configuration. The cursor is stored as a string and cast back based on the model's key type, so ordered string keys walk in order just like integers.

What matters is that the key is **unique and sortable**. A random UUIDv4 primary key is unique but its ordering is arbitrary — that still works correctly, it just means the traversal order looks random. ULIDs and UUIDv7 sort by creation time, which is usually what you want.

## Next

[Run it](/guide/running).
