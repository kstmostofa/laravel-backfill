# Backfill API

Everything available on a class extending `Kstmostofa\Backfill\Backfill`.

## Required

### `collection(): Builder`

The rows to process. Make it [self-excluding](/guide/writing-a-backfill#make-collection-self-excluding).

Any `orderBy` you add is stripped — [keyset pagination](/safety/keyset-pagination) is only correct when the sort matches the cursor column.

### `process($record): void`

Apply the change to one row. Called when `$hydrateModels` is true (the default).

Runs inside its own savepoint, so throwing rolls back this row alone and records the failure.

## Properties

| Property | Default | Description |
| --- | --- | --- |
| `$batchSize` | config | Rows per batch |
| `$sleepMs` | config | Milliseconds between batches |
| `$useTransactions` | `true` | Wrap each batch in a transaction. [Leave it on](/safety/transactions) |
| `$hydrateModels` | `true` | Set false for the query-builder fast path |
| `$withoutModelEvents` | `false` | Suppress observers for the run |
| `$operatorRunnable` | `false` | Offer this in the [operator panel](/features/operator-panel) |
| `$ledger` | `false` | Record processed rows for [external side effects](/advanced/side-effects) |
| `$externalSideEffects` | `false` | Declares that `process()` reaches outside the database |

`$batchSize` and `$sleepMs` fall back to `backfill.batch_size` and `backfill.sleep_ms` when not set on the class.

## Optional methods

### `processBatch(Collection $rows): void`

Whole-batch fast path, used when `$hydrateModels = false`. Rows arrive as `stdClass`.

### `guard(): bool`

Return false to refuse to start. Checked before the lock is taken, so a refused run leaves nothing behind.

### `keyName(): string`

Column to paginate over. Defaults to the model's primary key. Must be unique, sortable and indexed.

### `description(): string`

Human-readable name, shown in `backfill:list` and the operator panel.

### `name(): string`

The command-line name. Derived from the class name — `BackfillUserSlugs` becomes `user-slugs` — and rarely worth overriding.

An anonymous subclass answers to its parent's name, so a one-off tweak of a real backfill resumes and locks against the same run.

## Hooks

```php
public function beforeRun(BackfillRun $run): void {}
public function afterRun(BackfillRun $run): void {}
public function beforeBatch(Collection $rows, BackfillRun $run): void {}
public function afterBatch(Collection $rows, BackfillRun $run): void {}
public function onRowFailed($record, Throwable $e): void {}
```

::: warning `beforeBatch` and `afterBatch` run inside the batch transaction
Throwing from either rolls back the whole batch, cursor included.
:::

## Parameters

```php
public function parameters(): array;          // declare
public function parameter(string $key, mixed $default = null): mixed;   // read
public function parameterValues(): array;
public function withParameters(array $values): static;
```

See the [parameter reference](/reference/parameters).

## Tenancy

```php
public function tenants(): ?iterable;         // null = single-tenant
public function useTenant(string|int $tenant): void;
```

See [multi-tenancy](/advanced/multi-tenancy).

## Run options

Passed as `RunOptions` to `BackfillRunner::run()`, or as an array to the test helper.

| Option | Type | Description |
| --- | --- | --- |
| `batchSize` | `?int` | Override rows per batch |
| `sleepMs` | `?int` | Override the pause between batches |
| `fresh` | `bool` | Ignore a resumable run |
| `withoutEstimate` | `bool` | Skip the `COUNT` |
| `maxBatches` | `?int` | Stop cleanly after N batches |
| `startedBy` | `?string` | Audit string recorded on the run |
| `force` | `bool` | Skip production guards |
| `parameters` | `array` | Validated parameter values |
| `tenant` | `?string` | Which tenant's cursor this run belongs to |
| `onBatch` | `?Closure` | `fn (BackfillRun $run, int $count)` |
| `onThrottle` | `?Closure` | `fn (ThrottleDecision $decision)` |

## Running programmatically

```php
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;

$run = app(BackfillRunner::class)->run(new BackfillUserSlugs, new RunOptions(
    batchSize: 500,
    maxBatches: 10,
    startedBy: 'scheduler',
));

$runs = app(BackfillRunner::class)->runAll(new BackfillTenantInvoices);
```

`resumableRun(Backfill $backfill, ?string $tenant = null)` returns the run that would be continued, or null. It marks a stale run interrupted as a side effect.

## Run statuses

| Status | Meaning | Resumable |
| --- | --- | --- |
| `Pending` | Created, not yet started | Yes |
| `Running` | In progress | — |
| `Paused` | Stopped cleanly | Yes |
| `Interrupted` | Hard-killed; heartbeat went cold | Yes |
| `Failed` | Stopped with an error | Yes |
| `Completed` | Finished | No |
| `Cancelled` | Stopped for good | No |
