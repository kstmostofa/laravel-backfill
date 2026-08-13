# Keyset pagination

This package only ever paginates with `WHERE id > ? ORDER BY id LIMIT ?`. There is no option to do it any other way, and that is deliberate.

## The bug it prevents

`chunk()` pages with `LIMIT/OFFSET`:

```sql
SELECT * FROM users WHERE slug IS NULL ORDER BY id LIMIT 1000 OFFSET 0;
SELECT * FROM users WHERE slug IS NULL ORDER BY id LIMIT 1000 OFFSET 1000;
SELECT * FROM users WHERE slug IS NULL ORDER BY id LIMIT 1000 OFFSET 2000;
```

That is correct for a result set that stays still. A backfill's result set does not stay still — the whole point of a self-excluding collection is that processed rows leave it.

Walk through it. Batch one takes offsets 0–999 and gives all thousand a slug. Those rows now fail `slug IS NULL`, so the result set shrinks by a thousand and everything below shifts down. Batch two asks for offsets 1000–1999 of the *new* result set, which is where rows 2000–2999 now live. Rows 1000–1999 were shifted into offsets 0–999 and are never visited.

Every batch skips as many rows as the last batch removed. **Roughly half the table is silently missed** — no error, no warning, just a job that finishes early and a `count()` that looks close enough to believe.

## What this does instead

```sql
SELECT * FROM users WHERE slug IS NULL AND id > 0    ORDER BY id LIMIT 1000;
SELECT * FROM users WHERE slug IS NULL AND id > 1000 ORDER BY id LIMIT 1000;
SELECT * FROM users WHERE slug IS NULL AND id > 2140 ORDER BY id LIMIT 1000;
```

The cursor is a value, not a position. It cannot drift when rows leave the result set, because it does not count anything — it just says "everything up to here is done".

This also happens to be much faster on large tables. `OFFSET 4000000` makes the database walk and discard four million rows before returning anything; `id > 4000000` seeks straight into the index.

## The regression test

The suite reproduces the bug directly rather than describing it, so the claim is checked rather than asserted:

```php
it('demonstrates the offset bug that keyset pagination avoids', function () {
    User::seedUnslugged(10);

    $processed = 0;
    $page = 0;

    while (true) {
        $rows = User::query()->whereNull('slug')
            ->orderBy('id')->offset($page * 2)->limit(2)->get();

        if ($rows->isEmpty()) break;

        foreach ($rows as $row) {
            $row->forceFill(['slug' => 'x'])->save();
            $processed++;
        }

        $page++;
    }

    // The offset walk misses rows; the keyset runner does not.
    expect($processed)->toBeLessThan(10)
        ->and(User::whereNull('slug')->count())->toBeGreaterThan(0);
});
```

## Your ordering is stripped

Keyset pagination is only correct when the sort matches the cursor column. If `collection()` arrives with its own `orderBy`, the runner calls `reorder()` and replaces it:

```php
public function collection(): Builder
{
    // The descending order here is discarded. Keeping it would break
    // the cursor outright — `id > 500 ORDER BY id DESC` returns the
    // wrong rows in the wrong order and the walk never terminates.
    return User::query()->whereNull('slug')->orderByDesc('id');
}
```

If you need a specific processing order, that is what `keyName()` is for — pick the column to walk. You cannot choose the direction.

## Choosing the cursor column

`keyName()` defaults to the model's primary key. Whatever you choose must be:

- **Unique.** A duplicate value means `id > ?` skips every row sharing it.
- **Sortable.** Integers, ULIDs, UUIDv7, timestamps with sub-second precision.
- **Indexed.** Otherwise every batch sorts the whole table.

That last one is easy to get wrong, so the [dry run checks it for you](/features/dry-run#is-the-cursor-column-indexed) and says so in plain terms.

## Rows added behind the cursor

The one thing keyset pagination cannot do is look backwards. A row inserted with an id below the current cursor is not visited by that run.

This is inherent, not a bug to be fixed — the alternative is re-scanning from zero on every batch. In practice it barely matters, because a self-excluding collection means running the backfill a second time afterwards catches whatever arrived late, and does nothing to the rows already done.

For a table with continuous inserts, run the backfill, then run it again once it finishes.
