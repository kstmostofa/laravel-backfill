# Installation

```bash
composer require kstmostofa/laravel-backfill
php artisan migrate
```

That is the whole install. The migrations ship with the package and load automatically, so `migrate` picks them up without publishing anything.

## What the migration creates

Five small bookkeeping tables. None of them touch your data; they record what a backfill did and how far it got.

| Table | What it holds |
| --- | --- |
| `backfill_runs` | One row per run: status, cursor, counters, heartbeat, who started it |
| `backfill_run_errors` | One row per failed record, with exception, message and trace |
| `backfill_locks` | The run lock — a unique index is what stops two processes running the same backfill |
| `backfill_run_batches` | Optional per-batch audit trail, off by default |
| `backfill_ledger` | Used only by [ledger mode](/advanced/side-effects) |

See the [schema reference](/reference/schema) for the columns.

## Publishing

Publish the config if you want to change any defaults:

```bash
php artisan vendor:publish --tag=backfill-config
```

The migrations and dashboard views can be published too, though you rarely need to:

```bash
php artisan vendor:publish --tag=backfill-migrations
php artisan vendor:publish --tag=backfill-views
```

## Where backfills live

By default the package scans `app/Backfills`. Change it with the `path` config key:

```php
// config/backfill.php
'path' => app_path('Backfills'),
```

Discovery reads the namespace and class name straight out of each file, so your backfills do not have to live under the app namespace — a package or a module directory works just as well.

## Optional: the dashboards

The [engineer dashboard](/features/dashboard) and [operator panel](/features/operator-panel) need Livewire:

```bash
composer require livewire/livewire
```

```php
// config/backfill.php
'dashboard' => [
    'enabled' => true,
    'path' => 'backfills',
    'operator_path' => 'backfills/tasks',
    'middleware' => ['web'],
],
```

Without Livewire the package works exactly as before — the dashboards simply do not register. Nothing errors and nothing needs configuring.

::: warning Both panels are closed by default
Outside `local`, access is denied until you say otherwise. They can start and cancel data changes over production tables, so opening them is a deliberate act. See [authorisation](/features/dashboard#authorisation).
:::

## Optional: the Pulse card

With [Laravel Pulse](https://pulse.laravel.com) installed, add the card to your Pulse dashboard view:

```blade
<livewire:backfill-pulse-card cols="6" />
```

See [the Pulse card](/features/pulse).

## Next

[Write your first backfill](/guide/writing-a-backfill).
