# Running a backfill

```bash
php artisan backfill:list                       # what exists, and how each one last went
php artisan backfill:run user-slugs --dry-run   # scope, index check, real diffs, zero writes
php artisan backfill:run user-slugs             # do it
php artisan backfill:status user-slugs          # how is it going
```

## Always dry-run first

```bash
php artisan backfill:run user-slugs --dry-run
```

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
```

Four questions answered before you commit to anything: how much work is there, is the cursor column indexed, roughly how long will it take, and does `process()` actually do what you think. [More on the dry run](/features/dry-run).

## Running it

```bash
php artisan backfill:run user-slugs
```

A progress bar tracks the estimate. The run is resumable from the moment the first batch commits, so you can stop worrying about whether it finishes in one sitting.

In production you get a confirmation prompt. `--force` skips it, for CI.

## Stopping and starting

```bash
php artisan backfill:pause user-slugs    # stop cleanly after the batch in flight
php artisan backfill:resume user-slugs   # carry on from the committed cursor
php artisan backfill:cancel user-slugs   # stop for good; will not resume
```

Pausing is cooperative: the runner re-reads its status between batches, so it stops only once the batch in flight has committed its cursor. Nothing is lost, and nothing is half-applied.

`Ctrl-C` and `SIGTERM` do the same thing — the current batch finishes, commits, and the run is marked paused. That is what makes a deploy safe: the worker gets `SIGTERM`, finishes its batch, and the next run picks up where it stopped.

A hard `kill -9` costs you the batch in flight and nothing else. The run is left marked `running` with a cold heartbeat, and the next `backfill:run` spots that, marks it `interrupted`, and offers it back.

## Watching it

```bash
php artisan backfill:status user-slugs
```

```
  Run ......................................................... #14
  Status .................................................. Running
  Progress ................... 2,140,000 / 8,412,663 (25.4%)
  Failed ........................................................ 12
  Batches ................................ 2,140 of 1,000
  Cursor .................................................. 2140317
  Throughput .......................................... 1,240 rows/sec
  Started ................................................ 2 hours ago
  Heartbeat ......................................... 1 second ago
  Started by ......................................... cli:deploy
```

It also lists the ten most recent failed rows with their exceptions. There is a live [dashboard](/features/dashboard) if you would rather watch it in a browser.

## When rows fail

A row that throws is rolled back on its own, recorded in `backfill_run_errors`, and the run carries on. Once you have fixed the cause:

```bash
php artisan backfill:retry-failed user-slugs
```

That re-processes **only** the recorded failures, rather than walking eight million rows to reach the two hundred that matter. [More on failures](/safety/failures).

## Useful flags

| Flag | What it does |
| --- | --- |
| `--dry-run` | Report only, write nothing |
| `--samples=` | Rows to sample during a dry run (default 5) |
| `--queue` | Run as a chain of short queued jobs |
| `--batches-per-job=` | Batches each queued job handles before chaining |
| `--fresh` | Ignore a resumable run and start from the beginning |
| `--batch-size=` | Override rows per batch |
| `--sleep=` | Override the pause between batches |
| `--max-batches=` | Stop cleanly after N batches |
| `--no-count` | Skip the up-front `COUNT` on tables too big to count |
| `--param=key=value` | Set a [declared parameter](/reference/parameters) |
| `--tenant=` | Run one [tenant](/advanced/multi-tenancy) instead of all |
| `--force` | Skip the production guards and confirmation |

## Long runs belong on the queue

For anything that takes hours, use [queue mode](/features/queue):

```bash
php artisan backfill:run user-slugs --queue
```

It dispatches a job that runs 25 batches and queues the next one. A worker restart mid-deploy costs at most one batch, and nothing depends on your terminal staying open.

## Next

Understand [what makes it safe](/safety/invariant), or read about [the dry run](/features/dry-run).
