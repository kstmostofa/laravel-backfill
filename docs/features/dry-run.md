# The dry run

```bash
php artisan backfill:run user-slugs --dry-run
```

A dry run that only prints the query tells you nothing about whether `process()` does what you think. This one actually processes a handful of rows, inside a transaction that is always rolled back, with everything that cannot be rolled back intercepted first.

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

## How many rows

A `COUNT` over `collection()`. This is the number that catches a wrong `where` clause — "8.4 million" when you expected three thousand is the cheapest bug report you will ever get.

## Is the cursor column indexed

The question is narrower than "is any index used". A backfill can happily use an index for its `WHERE` clause and still sort the entire table on every single batch, because the *cursor* column is unindexed. That is the difference between a ten-minute job and a three-day one.

So what the plan check actually asks is whether the `ORDER BY` is satisfied by an index. Each engine reports that differently:

| Engine | The bad sign |
| --- | --- |
| MySQL | `Using filesort` in Extra |
| PostgreSQL | a `Sort` node above the scan |
| SQLite | `USE TEMP B-TREE FOR ORDER BY` |

The query is explained **with the cursor predicate in place**, which matters more than it sounds. Without `id > ?`, SQLite reports `SCAN bf_users`; with it, `SEARCH bf_users USING INTEGER PRIMARY KEY (rowid>?)`. Explaining the query without the predicate would describe something the runner never issues.

::: warning PostgreSQL and small tables
PostgreSQL sensibly chooses a sequential scan and a sort on tiny tables no matter what indexes exist, so a dry run against a near-empty staging table can report `NOT INDEXED` for a perfectly well-indexed column. The message says so. Check it against production-sized data before believing it.
:::

## Roughly how long

The sample is timed and extrapolated across the full scope. It is deliberately rough — it exists to tell apart "ten minutes" from "three days", not to be accurate to the minute. Real runs also sleep between batches and slow down under [throttling](/safety/throttling), neither of which the estimate knows about.

Two things make the number honest rather than decorative, both learned from being badly wrong against a real 8M-row table:

**The two paths scale differently.** `process()` costs per row, so its sample scales by row count. A whole `processBatch()` costs about the same whether it touches three rows or five thousand, so scaling *that* by row count is meaningless — it once reported `~1.8h` for a job that took 75 seconds. On the un-hydrated path the dry run therefore times one **full batch** and multiplies by the batch count, showing you only the first few diffs.

**The first row is discarded as warm-up.** It pays for connection setup, query compilation and booting the model — costs the other eight million rows never see. With the default five-row sample that one row was most of the measurement, which turned a three-minute job into an advertised half hour.

The estimate still improves with a bigger sample, because per-row noise averages out:

| `--samples` | Estimate | Actual |
| --- | --- | --- |
| 5 (default) | ~5m | ~3.6m |
| 50 | ~4m | ~3.6m |
| 500 | ~3m | ~3.6m |

If you are deciding something expensive on the basis of the number, pass `--samples=200` and trust it more.

## What would actually change

The sampled rows are genuinely processed. The diff compares each row's attributes before and after against the database, inside the open transaction, then the whole thing is rolled back.

That means it catches the failure mode a query-only preview cannot:

```
  | 1041 | no change                                          |
  | 1042 | no change                                          |

  None of the sampled rows changed. Either the work is already done,
  or process() is not doing what you expect.
```

Rows that would fail are shown as failures rather than quietly omitted:

```
  | 1043 | would fail: Call to a member function format() on null |
```

And a row that `process()` deletes is reported as such.

::: tip What the diff does not cover
The diff shows the sampled rows themselves. If `process()` also writes to another table, the rollback still protects you, but that change will not appear in the table above.
:::

## Nothing escapes

Database writes are handled by the rollback. Everything else has to be stopped before it happens — a "dry" run that emails four million customers is the single worst thing this package could do.

| Intercepted | How |
| --- | --- |
| Mail | Swapped to the array transport |
| Notifications | `Notification::fake()` |
| Queued jobs | `Queue::fake()` |
| Dispatched jobs | `Bus::fake()` |
| HTTP | `Http::fake()` + `preventStrayRequests()` |

Counts are reported per sample and extrapolated, so "5 across 5 rows — roughly 8,412,663 in full" tells you what a real run would send.

### Two deliberate choices

**Mail uses the array transport, not `Mail::fake()`.** Laravel's `MailFake::raw()` is an empty method that records nothing. A dry run built on it would silently lose every `Mail::raw()` call and cheerfully report that no mail would be sent — exactly the false reassurance that makes a dry run dangerous. The array transport sends nothing and captures everything, `raw()` included.

**Events are recorded but not suppressed.** Faking events would stop model observers running, and the before/after diff would then show something a real run would never produce. Application events are counted and reported instead:

```
  Application events fired (not suppressed, so observers still ran):
  OrderReceiptQueued ........................................... 5
```

Framework-internal events (`eloquent.*`, `Illuminate\*`) are filtered out as noise.

::: warning HTTP interception is not total
Only Laravel's HTTP client is intercepted. A raw cURL call or a vendor SDK with its own transport inside `process()` will still reach the internet during a dry run.
:::

## Options

```bash
php artisan backfill:run user-slugs --dry-run --samples=20
```

`--samples` overrides `backfill.dry_run.samples` (default 5). More samples give a better duration estimate and a broader look at the diffs, at the cost of a longer transaction.

## What a dry run does not do

It does not create a run record. There is no history of who dry-ran what, and a dry run never interferes with resuming a real one.
