# Multi-tenancy

A backfill across a multi-tenant application is not one backfill. It is a hundred, each with its own data, its own size, and its own chance of going wrong.

Declaring tenants gives each one its own cursor, its own run row and its own lock.

```php
class BackfillTenantInvoices extends Backfill
{
    public function tenants(): ?iterable
    {
        return Tenant::query()->pluck('id');
    }

    public function useTenant(string|int $tenant): void
    {
        Tenant::find($tenant)->makeCurrent();
    }

    public function collection(): Builder
    {
        // Runs in the current tenant's context.
        return Invoice::query()->whereNull('reference');
    }
}
```

`useTenant()` is called before that tenant's cursor is read, so `collection()` always sees the right data. Whatever your tenancy package uses — a connection switch, a global scope, a bound container value — goes there.

Return `null` from `tenants()` (the default) for a single-tenant backfill.

## Running it

```bash
php artisan backfill:run tenant-invoices              # every tenant, in turn
php artisan backfill:run tenant-invoices --tenant=acme  # just this one
```

Running every tenant reports each as it finishes:

```
  acme ...................... 12,400 processed, 0 failed — completed
  globex ..................... 3,120 processed, 2 failed — completed
  initech ........................ 0 processed, 0 failed — completed
```

The command exits non-zero if any tenant failed, so CI notices.

## Why separate runs matter

**One tenant crashing does not rewind another.** Each cursor is independent. A tenant whose data trips a bug can be paused, fixed and resumed while the rest carry on.

**The locks are independent too.** The lock key is `backfill:tenant`, so separate workers can process different tenants concurrently. Running the same tenant twice is still refused.

**Failures stay local.** `resumableRun($backfill, 'acme')` looks only at acme's runs; globex's history is untouched.

```php
$runner->resumableRun(new BackfillTenantInvoices, 'acme');    // acme's run
$runner->resumableRun(new BackfillTenantInvoices, 'globex');  // globex's run
```

## Running tenants in parallel

`backfill:run` walks tenants sequentially. To run several at once, dispatch a job per tenant:

```php
use Kstmostofa\Backfill\Jobs\RunBackfillJob;

foreach (Tenant::pluck('id') as $tenant) {
    RunBackfillJob::dispatch('tenant-invoices', tenant: (string) $tenant)
        ->onQueue('backfills');
}
```

Each chains its own follow-up jobs and holds its own lock. With four workers you get four tenants at a time.

::: warning Watch the primary
Ten tenants in parallel is ten times the load on a shared database. If they share infrastructure, turn on [throttling](/safety/throttling) or keep the worker count low.
:::

## From application code

```php
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;

$runs = app(BackfillRunner::class)->runAll(new BackfillTenantInvoices);

foreach ($runs as $run) {
    echo "{$run->tenant}: {$run->processed_count} processed\n";
}
```

`runAll()` returns one run per tenant. `run()` with `RunOptions(tenant: 'acme')` does a single one.

## Where the tenant shows up

The `tenant` column on `backfill_runs` appears in `backfill:status`, the [dashboard](/features/dashboard), and the [Pulse card](/features/pulse), so a paused run always says which tenant it belongs to.

## A changing tenant list

`tenants()` is read fresh at the start of each run. A tenant that appears later is picked up next time; one that disappears is skipped, and its historical runs stay in the table. No bookkeeping on your part.
