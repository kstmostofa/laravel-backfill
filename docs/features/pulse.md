# Pulse card

With [Laravel Pulse](https://pulse.laravel.com) installed, backfills that need attention can sit alongside your slow queries and failed jobs.

```bash
composer require laravel/pulse
```

```blade
<!-- resources/views/vendor/pulse/dashboard.blade.php -->
<x-pulse>
    <livewire:backfill-pulse-card cols="6" />

    <livewire:pulse.queues cols="6" />
    <livewire:pulse.slow-queries cols="full" />
</x-pulse>
```

The card registers itself when Pulse is installed and stays out of the way when it is not.

## What it shows

Runs that are **in flight or want attention** — running, paused, interrupted, failed, or pending — with the reason a stopped run stopped, and a `stale` marker when a heartbeat has gone cold.

Ordering is by how much you should care, not alphabetically or by time:

1. Failed
2. Interrupted
3. Paused
4. Running
5. Pending

A failed run is the thing you opened Pulse to find, so it goes first regardless of its name or age.

Each row shows progress as a percentage when the scope is known, or a raw processed count when the backfill was started with `--no-count`. Failures are called out in red. [Multi-tenant](/advanced/multi-tenancy) runs show which tenant they belong to.

## No period filter

Pulse cards are normally scoped to the dashboard's selected period. This one deliberately ignores it.

A backfill that has been paused for three days is exactly what you want on the dashboard, and a one-hour period filter would hide it. The card asks a different question from the rest of Pulse: not "what happened recently" but "what is unfinished right now".

Completed and cancelled runs never appear — there is nothing to do about them. Use `backfill:list` or the [dashboard](/features/dashboard) for history.

## No recorder

The card queries `backfill_runs` directly rather than writing to Pulse's own storage through a recorder.

There is no point sampling and aggregating a table that already holds exactly the state we want, updated every batch. It also means the card works immediately after installing Pulse, with no ingest step and no gap while data accumulates.

## Testing it

The card is `#[Lazy]`, like Pulse's own cards, so its first render is a placeholder. Turn that off in tests:

```php
use Livewire\Livewire;

Livewire::withoutLazyLoading();

Livewire::test(BackfillsCard::class)->assertSee('user-slugs');
```
