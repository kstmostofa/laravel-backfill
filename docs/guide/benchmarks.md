# Benchmarks

Measured on a real 8,000,000-row table, not extrapolated from a small one.

::: info The machine
Apple Silicon laptop, MySQL 8.4 with a stock **128 MB** InnoDB buffer pool, against a `users` table that grew to **923 MB** — several times larger than the pool, so these numbers include real disk I/O rather than a fully cached working set. Treat them as a floor.
:::

## Throughput

The backfill filled a nullable `slug` column and bumped a counter on every row.

| Rows | Un-hydrated (`processBatch`) | Hydrated (`process`) |
| --- | --- | --- |
| 1,000,000 | **8s** | ~3.6m |
| 2,000,000 | **17s** | ~7m |
| 8,000,000 | **75s** | ~29m |

| Path | Throughput |
| --- | --- |
| Un-hydrated bulk `UPDATE` | **~110,000 rows/sec** |
| Hydrated Eloquent models | **~4,600 rows/sec** |

The hydrated figures at 2M and 8M are extrapolated from a measured 50,000-row slice; the un-hydrated figures are whole runs, end to end.

## What that gap means

Roughly **24×**, and it is the most consequential choice you will make for a large backfill.

```php
// ~4,600 rows/sec — a model per row
public function process($record): void
{
    $record->update(['slug' => Str::slug($record->name)]);
}
```

```php
// ~110,000 rows/sec — one UPDATE per batch
public bool $hydrateModels = false;

public function processBatch(Collection $rows): void
{
    DB::table('users')->whereIn('id', $rows->pluck('id'))->update([
        'slug' => DB::raw("concat(lower(replace(name, ' ', '-')), '-', id)"),
    ]);
}
```

Use the hydrated path when you need model logic — casts, accessors, relationships, observers, anything expressed in PHP. Use the [fast path](/guide/writing-a-backfill#the-fast-path) when the change is expressible in SQL.

The trade is per-row error isolation: on the fast path a failure fails the whole batch instead of recording one bad row and carrying on. On an 8M-row job that is usually the right trade, because the alternative costs half an hour.

## Other timings at 8M

| Operation | Time |
| --- | --- |
| `COUNT` for the dry run's scope | ~1s |
| Dry run, start to finish | ~1s |
| Seeding 8M rows (`INSERT … SELECT` doubling) | ~30s |
| Resume after a `kill -9` at 38% | 39s for the remaining 4.95M |

## The invariant, at 8M

The claim is that a backfill can be killed at any instant and resume to the same end state. Here it is against eight million rows rather than a fixture.

A live run was sent an uncatchable `SIGKILL` 25 seconds in. The state it left behind:

```
status:                  running        (nothing got the chance to say otherwise)
cursor:                  10,309,908
processed_count:         3,045,000
rows actually slugged:   3,045,000      ← exactly equal
lock:                    orphaned
```

`processed_count` and the rows actually written **agree exactly**. No partial batch, no cursor claiming work that was rolled back — the batch in flight was discarded whole by the server when the connection dropped.

After the heartbeat went cold, `backfill:resume`:

```
rows unprocessed:          0
processed exactly once:    8,000,000
processed more than once:  0
run rows:                  1           (resumed, not restarted)
locks left behind:         0
```

Eight million rows, one hard kill, **zero duplicated and zero skipped**.

## Reproducing this

The whole setup is in [local development](/guide/local-development). Growing the table is the only unusual part — factories would take hours, so seed by doubling inside the database:

```php
DB::statement("
    insert into users (name, email, password, created_at, updated_at, slug, process_count)
    select name, concat('user', id + {$offset}, '@example.test'), password,
           created_at, updated_at, null, 0
    from users order by id limit {$take}
");
```

That reaches 8M rows in about 30 seconds.

## Reading these numbers

They are a **floor, not a promise**. Your backfill's `process()` does real work; the one measured here writes two columns. A run that calls an API per row is bounded by that API, not by anything here.

What does transfer is the shape: the fast path is roughly an order of magnitude quicker, the per-batch overhead is small, and throughput stays flat as the table grows — 1M, 2M and 8M all ran at about the same rows/sec, because [keyset pagination](/safety/keyset-pagination) seeks rather than scans. An `OFFSET`-based loop degrades as the offset grows; this does not.
