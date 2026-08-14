# Production guards

Three questions worth answering before a data change starts: is this running somewhere it should not be, is it bigger than whoever typed the command expects, and is now a sane time.

## It refuses to run inside a migration

Not configurable. Migrations run synchronously during deploy, usually inside a transaction, so a multi-million row change there blocks the pipeline and risks a statement timeout.

```
Backfill [user-slugs] cannot run inside a migration. Migrations run
synchronously during deploy, often inside a transaction, so a long-running
data change there blocks the pipeline and risks a statement timeout. Run it
separately with `php artisan backfill:run user-slugs` once the deploy has
finished.
```

The package listens for Laravel's `MigrationsStarted` and `MigrationsEnded` events, so this holds however the migration was triggered.

The intended shape is: migration adds the nullable column, deploy finishes, backfill fills it in, a later migration adds the `NOT NULL` constraint.

## A ceiling on how much it will touch

```php
'guards' => [
    'max_rows_without_confirmation' => 1_000_000,
],
```

A run matching more rows than this is refused without `--force`:

```
Backfill [user-slugs] matches 8,412,663 rows, above the 1,000,000 row ceiling
set by backfill.guards.max_rows_without_confirmation. Dry-run it first with
`backfill:run user-slugs --dry-run`, then pass --force if the number is
expected.
```

The value of this guard is catching the case where `collection()` is wrong — a missing `where` clause that turns "the 3,000 orders from the incident" into "every order ever placed". The number in the message is usually the first sign.

Set it to `null` to disable. The row count is only performed when the guard is active and `--force` was not passed, so a disabled guard costs nothing.

### From the dashboard

`--force` has no keyboard on a web page, so the [dashboard](/features/dashboard) checks the guards *before* queueing anything. A run that would be refused stops and asks:

> **Hold on — user-slugs**
> Backfill [user-slugs] matches 8,000,000 rows, above the 1,000,000 row ceiling…
> **[ Run anyway ]  [ Cancel ]**

Nothing is queued until someone presses **Run anyway**, which dispatches the job with the override set. That is the same acknowledgement `--force` represents, just asked out loud.

The check happens before dispatch on purpose. Queueing first and letting the guard throw inside the worker means the page still says "never run" while `failed_jobs` quietly fills with stack traces — the refusal is invisible to the person who pressed the button.

The [operator panel](/features/operator-panel) deliberately has **no** override. An operator hitting a production guard means something is misconfigured — a missing ceiling on an id list, or a task exposed that should not have been — so they are told plainly and sent to a developer.

## Deploy freeze windows

```php
'guards' => [
    'deploy_freeze' => [
        'enabled' => true,
        'timezone' => 'Europe/London',
        'windows' => [
            ['days' => ['fri'], 'from' => '15:00', 'to' => '23:59'],
            ['days' => ['sat', 'sun'], 'from' => '00:00', 'to' => '23:59'],
            ['from' => '22:00', 'to' => '02:00'],   // nightly, wraps midnight
        ],
    ],
],
```

Starting a run inside a window is refused:

```
Backfill [user-slugs] cannot start during the deploy freeze window
fri 15:00–23:59 (Europe/London). Wait for the window to close, or pass
--force if this is the emergency it exists for.
```

Days use three-letter lowercase names (`mon` through `sun`). Omitting `days` makes the window apply daily. A window whose `from` is later than its `to` wraps past midnight, so `22:00`–`02:00` behaves the way you would expect.

The guard only blocks a run from **starting**. A run already in progress when a window opens keeps going — stopping mid-backfill would leave the table half-converted, which is usually worse than finishing.

## Who ran it

Every run records `started_by`, without configuration:

| Source | Recorded as |
| --- | --- |
| CLI | `cli:<system user>` |
| Queue | `queue` |
| Dashboard | `dashboard:<email or id>` |
| Operator panel | `operator:<email or id>` |

It shows in `backfill:status`, `backfill:list` and both dashboards. When someone asks who ran the thing that changed four million rows last Tuesday, the answer is in the run row.

## The confirmation prompt

In production, `backfill:run` prompts before starting, via Laravel's standard `ConfirmableTrait`. `--force` skips it — that is what CI and deploy scripts should pass.

`--force` also skips the row ceiling and the freeze windows, so treat it as "I have checked all three" rather than "make the prompt go away".
