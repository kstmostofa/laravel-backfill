---
layout: home

hero:
  name: Laravel Backfill
  text: Data backfills you can kill
  tagline: Safe, resumable, one-off data changes over large tables — keyset paginated, crash-proof, and testable.
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: Why not just a migration?
      link: /guide/introduction#why-not-just-a-migration
    - theme: alt
      text: View on GitHub
      link: https://github.com/kstmostofa/laravel-backfill

features:
  - icon: 💀
    title: Killable at any instant
    details: Every batch commits its work, its errors and its cursor together. A SIGKILL mid-batch costs one batch, and the resume lands on the same end state as an uninterrupted run.
    link: /safety/invariant
    linkText: The invariant

  - icon: 🎯
    title: Never skips rows
    details: chunk() pages with OFFSET, so a self-excluding backfill silently misses about half the table. This only ever walks WHERE id > ? ORDER BY id, and that is not configurable.
    link: /safety/keyset-pagination
    linkText: Keyset pagination

  - icon: 🔍
    title: A dry run that means something
    details: Real before-and-after diffs from rows genuinely processed inside a rolled-back transaction, with mail, jobs and HTTP intercepted before they escape.
    link: /features/dry-run
    linkText: See the output

  - icon: 🛡️
    title: One bad row cannot stop it
    details: Per-row savepoints, recorded failures, and a circuit breaker that pauses the run when failures stop looking like bad rows and start looking like a bad assumption.
    link: /safety/failures
    linkText: Failures and retries

  - icon: 🙋
    title: Support staff can run it
    details: Mark a backfill operator-runnable, declare its inputs, and someone in support can paste a list of ids and press Run — no shell, no developer, no way to reach anything you did not expose.
    link: /features/operator-panel
    linkText: The operator panel

  - icon: 🩺
    title: Kind to your replicas
    details: Watches replication lag and slows down before anyone notices, halving batches between the soft and hard thresholds and pausing rather than pushing replicas further behind.
    link: /safety/throttling
    linkText: Throttling

  - icon: ⚡
    title: 8M rows in 75 seconds
    details: Measured, not estimated — ~110,000 rows/sec on the bulk path against a 923 MB table, with throughput flat from 1M to 8M because keyset pagination seeks rather than scans.
    link: /guide/benchmarks
    linkText: Benchmarks

  - icon: 📊
    title: A dashboard and a task panel
    details: Live progress, throughput, batch-duration sparkline and failed rows for engineers; a separate gated panel where support staff paste ids and watch a progress bar.
    link: /features/dashboard
    linkText: The dashboard

  - icon: 🧪
    title: Tested where it counts
    details: 258 tests green on SQLite, MySQL 8.4 and PostgreSQL 18, including a chaos test that forks and sends the child a real SIGKILL from inside a batch transaction.
    link: /advanced/testing
    linkText: Testing
---

## Install

```bash
composer require kstmostofa/laravel-backfill
php artisan migrate
```

## Write one

```php
class BackfillUserSlugs extends Backfill
{
    public int $batchSize = 1000;

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

## Run it

```bash
php artisan backfill:run user-slugs --dry-run   # scope, index check, real diffs, zero writes
php artisan backfill:run user-slugs             # resumable, killable, throttled
```

## The test behind the claim

The promise that you can kill a backfill at any moment is worth exactly as much as the test behind it. So the test does not simulate a crash: it forks, and the child sends **itself a real `SIGKILL` from inside a batch transaction** — uncatchable, no destructors, no shutdown handlers. It then asserts the resumed run reaches byte-for-byte the same end state as an uninterrupted control run, with every row processed exactly once.

```
   PASS  Tests\Chaos\HardKillTest
  ✓ it resumes after a SIGKILL to the identical end state                1.19s
  ✓ it leaves no work half-applied when killed inside a batch            1.11s

  Tests:    245 passed (554 assertions)
```

Green on SQLite, MySQL 8.4 and PostgreSQL 18, with the chaos test running for real on each.
