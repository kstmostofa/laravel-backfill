# Commands

## `make:backfill`

```bash
php artisan make:backfill BackfillUserSlugs
```

Generates a class in `config('backfill.path')`, namespaced to match. Supports subdirectories: `make:backfill Orders/BackfillReceipts`.

## `backfill:list`

```bash
php artisan backfill:list
```

Every discovered backfill with the status of its last run, processed and failed counts, and cursor.

## `backfill:run`

```bash
php artisan backfill:run <name>
```

Resumes the last resumable run, or starts a new one. Accepts the short name (`user-slugs`), the class basename (`BackfillUserSlugs`), or the fully qualified class name.

| Flag | Description |
| --- | --- |
| `--dry-run` | Report what would happen; write nothing |
| `--samples=` | Rows to sample during a dry run (default 5) |
| `--queue` | Run as a chain of short queued jobs |
| `--batches-per-job=` | Batches each queued job handles before chaining |
| `--fresh` | Ignore a resumable run and start from the beginning |
| `--batch-size=` | Rows per batch |
| `--sleep=` | Milliseconds between batches |
| `--max-batches=` | Stop cleanly after N batches |
| `--no-count` | Skip the up-front `COUNT` |
| `--param=key=value` | Set a declared parameter; repeatable |
| `--tenant=` | Run one tenant instead of all |
| `--force` | Skip production guards and confirmation |

Exit code is `0` for completed, paused and cancelled runs, and `1` for failures or a refusal.

## `backfill:status`

```bash
php artisan backfill:status <name>
```

Progress, throughput, cursor, heartbeat, who started it, why it stopped, and the ten most recent failed rows. Also reports the tenant, ledger skips, parameters and unconfirmed ledger claims when they apply.

## `backfill:pause`

```bash
php artisan backfill:pause <name>
```

Marks the run paused. The worker stops after the batch in flight commits its cursor — nothing is lost and nothing is half-applied.

## `backfill:resume`

```bash
php artisan backfill:resume <name>
```

Continues from the committed cursor. Accepts `--batch-size`, `--sleep`, `--max-batches` and `--force`. Fails if there is nothing to resume.

## `backfill:cancel`

```bash
php artisan backfill:cancel <name>
```

Marks the run cancelled so it will not resume. Work already committed is kept; `--fresh` starts over from the beginning.

## `backfill:retry-failed`

```bash
php artisan backfill:retry-failed <name>
php artisan backfill:retry-failed <name> --limit=100
php artisan backfill:retry-failed <name> --run=14
```

Re-processes only the rows recorded as failed. Successes are marked resolved and the run's counters corrected; failures have their attempt count incremented. Exits non-zero if any row still fails.

## Pruning

Not a command of ours — finished runs are pruned through Laravel's:

```bash
php artisan model:prune --model="Kstmostofa\Backfill\Models\BackfillRun"
```

Retention comes from `prune_runs_after_days`. Paused and interrupted runs are never pruned.
