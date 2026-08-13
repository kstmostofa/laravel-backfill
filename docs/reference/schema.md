# Database schema

Five bookkeeping tables. None of them hold your data — they record what a backfill did and how far it got.

## `backfill_runs`

One row per run. This is the table that makes a backfill resumable.

| Column | Type | Description |
| --- | --- | --- |
| `id` | bigint | |
| `backfill` | string | The short name, e.g. `user-slugs` |
| `tenant` | string, null | Which [tenant](/advanced/multi-tenancy) this run belongs to |
| `backfill_class` | string | Fully qualified class name |
| `status` | string | `pending`, `running`, `paused`, `completed`, `failed`, `cancelled`, `interrupted` |
| `cursor` | string, null | Last committed key. String so integers, UUIDs and ULIDs all round-trip |
| `key_name` | string | Column paginated over |
| `total_estimate` | bigint, null | Row count at start; null with `--no-count` |
| `processed_count` | bigint | Rows successfully processed |
| `failed_count` | bigint | Rows that threw |
| `skipped_count` | bigint | Rows skipped by the [ledger](/advanced/side-effects) |
| `batch_count` | int | Batches committed |
| `batch_size` | int | Rows per batch as configured |
| `sleep_ms` | int | Pause between batches as configured |
| `dry_run` | bool | Reserved; dry runs do not create run records |
| `started_by` | string, null | `cli:user`, `queue`, `dashboard:…`, `operator:…` |
| `heartbeat_at` | timestamp | Updated every batch. A cold one means a crash |
| `started_at` | timestamp, null | |
| `finished_at` | timestamp, null | Null while paused — it may yet continue |
| `error` | text, null | Exception message for a failed run |
| `meta` | json, null | `stop_reason`, `stop_code`, `parameters`, `parameter_summary` |

The cursor is stored as a string deliberately: an integer column would lose UUID and ULID keys.

### `meta`

| Key | Description |
| --- | --- |
| `stop_reason` | Human-readable sentence explaining why it stopped |
| `stop_code` | Machine-readable [`StopReason`](/features/events#telling-pauses-apart) |
| `parameters` | Validated [parameter](/reference/parameters) values |
| `parameter_summary` | Readable summary for the audit trail |

## `backfill_run_errors`

One row per failed record.

| Column | Type | Description |
| --- | --- | --- |
| `id` | bigint | |
| `run_id` | bigint | |
| `record_id` | string, null | The key of the row that failed |
| `exception_class` | string | |
| `message` | text | Truncated at 60,000 characters |
| `trace` | longtext, null | |
| `attempts` | int | Incremented by `backfill:retry-failed` |
| `resolved_at` | timestamp, null | Set when a retry succeeds |

Written **inside the batch transaction**, so error records and the cursor commit together. This is why [per-row savepoints](/safety/transactions) matter on PostgreSQL — without them the insert is rejected along with everything else.

## `backfill_locks`

The run lock. A row here means someone is running that backfill.

| Column | Type | Description |
| --- | --- | --- |
| `id` | bigint | |
| `backfill` | string, **unique** | Lock key — `name` or `name:tenant` |
| `run_id` | bigint, null | Attached once the run row exists |
| `owner` | string, null | `hostname:pid` |
| `acquired_at` | timestamp, null | |
| `heartbeat_at` | timestamp, null | Refreshed every batch |

The unique index **is** the lock. Acquiring is an `insertOrIgnore`, which gives identical mutual exclusion on MySQL, PostgreSQL and SQLite — unlike a partial unique index, which MySQL does not have, or a cache key, which can be flushed.

A lock whose heartbeat is older than `stale_after` was abandoned by a killed process and is taken over automatically.

## `backfill_run_batches`

Per-batch audit trail. Off by default; enable with `record_batches`.

| Column | Type | Description |
| --- | --- | --- |
| `id` | bigint | |
| `run_id` | bigint | |
| `from_id` / `to_id` | string, null | Key range covered |
| `count` | int | Rows in the batch |
| `failed` | int | Rows that threw |
| `duration_ms` | int | How long it took |
| `attempts` | int | 1, or more after a transient retry |
| `created_at` | timestamp, null | |

Powers the [dashboard sparkline](/features/dashboard#the-sparkline) and answers "where did it slow down" after the fact.

## `backfill_ledger`

Used only by [ledger mode](/advanced/side-effects).

| Column | Type | Description |
| --- | --- | --- |
| `id` | bigint | |
| `backfill` | string | |
| `record_id` | string | Unique together with `backfill` |
| `run_id` | bigint, null | |
| `claimed_at` | timestamp, null | Set **before** `process()` runs |
| `processed_at` | timestamp, null | Set after it succeeds |

A row with `claimed_at` but no `processed_at` is one nobody can be sure about — the run stopped part way through it. Those are never retried automatically.

## Querying it yourself

```php
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Models\BackfillRunBatch;
use Kstmostofa\Backfill\Models\BackfillLedgerEntry;

$run = BackfillRun::where('backfill', 'user-slugs')->latest('id')->first();

$run->progressPercent();        // 25.4
$run->throughputPerSecond();    // 1240.0
$run->isStale();                // heartbeat gone cold?
$run->status->isResumable();

$run->errors()->whereNull('resolved_at')->get();
BackfillLedgerEntry::unconfirmed()->where('backfill', 'customer-emails')->get();
```
