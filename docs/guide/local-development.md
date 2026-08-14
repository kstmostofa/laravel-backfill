# Local development

How to run this package inside a real Laravel application before publishing it — for working on the package itself, or for trying it against your own data.

The whole trick is Composer's **path repository**, which symlinks the package into `vendor/` so edits are live immediately with no reinstall.

## Set up a testbed

```bash
cd ~/Projects

laravel new backfill-testbed --database=sqlite --phpunit --no-node
```

You end up with two sibling directories:

```
~/Projects/
  laravel-backfill/     the package
  backfill-testbed/     a real app that consumes it
```

## Wire the package in

```bash
cd backfill-testbed

composer config repositories.laravel-backfill path ../laravel-backfill
composer require "kstmostofa/laravel-backfill:@dev"
```

::: warning Require `@dev`, not `*`
A path repository takes its version from the branch — `dev-main` — which does not satisfy `*` under Composer's default `stable` minimum-stability:

```
Root composer.json requires kstmostofa/laravel-backfill *,
found kstmostofa/laravel-backfill[dev-main] but it does not match
your minimum-stability.
```

`"kstmostofa/laravel-backfill:@dev"` fixes it without loosening stability for everything else.
:::

Confirm it symlinked rather than copied — this is what makes edits live:

```bash
ls -la vendor/kstmostofa/
# laravel-backfill -> ../../../laravel-backfill/
```

```bash
php artisan migrate
php artisan backfill:list
```

Package discovery registers the service provider automatically, so the commands appear with no further wiring.

## Give it something to do

```bash
php artisan make:migration add_slug_to_users_table
```

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('slug')->nullable()->after('name');

    // Lets you prove every row was processed exactly once.
    $table->unsignedInteger('process_count')->default(0);
});
```

```bash
php artisan migrate
php artisan tinker --execute="App\Models\User::factory()->count(250)->create();"
php artisan make:backfill BackfillUserSlugs
```

```php
public int $batchSize = 25;

public function collection(): Builder
{
    return User::query()->whereNull('slug');
}

public function process($record): void
{
    // A couple of rows failing on purpose is worth keeping while you
    // exercise error isolation and backfill:retry-failed.
    if (in_array($record->id, [42, 137], true)) {
        throw new RuntimeException("User {$record->id} has no usable name.");
    }

    $record->forceFill([
        'slug' => Str::slug($record->name).'-'.$record->id,
        'process_count' => $record->process_count + 1,
    ])->save();
}
```

## Exercise it

```bash
php artisan backfill:run user-slugs --dry-run     # scope, index, diffs, no writes
php artisan backfill:run user-slugs               # the real thing
php artisan backfill:status user-slugs            # progress and failed rows
```

Check the result properly — the count that matters is "processed exactly once":

```bash
php artisan tinker --execute="
echo 'unslugged: ' . App\Models\User::whereNull('slug')->count() . PHP_EOL;
echo 'once: ' . App\Models\User::where('process_count', 1)->count() . PHP_EOL;
echo 'more than once: ' . App\Models\User::where('process_count', '>', 1)->count() . PHP_EOL;
"
```

### Resume

```bash
php artisan tinker --execute="App\Models\User::query()->update(['slug' => null, 'process_count' => 0]);"

php artisan backfill:run user-slugs --max-batches=3   # stops at cursor 75
php artisan backfill:resume user-slugs                # finishes the rest
```

One run row, not two — check `BackfillRun::count()`.

### Failed rows

```bash
php artisan backfill:retry-failed user-slugs   # exits 1 while rows still fail
```

Fix the cause in `process()`, run it again, and watch the run's `failed_count` drop to zero.

### Queue mode

```bash
php artisan backfill:run user-slugs --queue --batches-per-job=3
php artisan queue:work --stop-when-empty
```

Nothing should happen until the worker runs — that is the point.

### The dashboards

```bash
composer require livewire/livewire
php artisan vendor:publish --tag=backfill-config
```

```php
// config/backfill.php
'dashboard' => ['enabled' => true, ...],
'record_batches' => true,   // for the sparkline
```

```bash
php artisan serve
```

- `http://localhost:8000/backfills` — the engineer dashboard
- `http://localhost:8000/backfills/tasks` — the operator panel

Both are open in `local` without configuring a gate. In any other environment they are closed until you call `Dashboard::auth()` — see [the dashboard](/features/dashboard#authorisation).

For the operator panel you need a backfill with `$operatorRunnable = true` and some [parameters](/reference/parameters); one without them will not appear.

## Working on the package

Because `vendor/kstmostofa/laravel-backfill` is a symlink, editing the package is immediately reflected in the app. No `composer update`, no cache clear for PHP changes.

Two things do need a nudge:

```bash
php artisan config:clear   # after editing config/backfill.php in the package
php artisan view:clear     # after editing the dashboard Blade views
```

Run the package's own suite from the package directory, not the app:

```bash
cd ../laravel-backfill
composer test
BACKFILL_DRIVER=mysql composer test
BACKFILL_DRIVER=pgsql composer test
```

## Why bother, when the suite is green

The package's 245 tests run under Orchestra Testbench, which is a real Laravel application — but not *your* Laravel application. Building this testbed immediately surfaced two bugs the suite could not have caught:

**Laravel's console output strips `U+2192`.** The dry run's diff was built with a `→` between the before and after values. In Testbench assertions the string is compared in memory, so it passed; printed through Artisan in a real app, the arrow vanished and rows rendered as `slug: null user-1`. Em dash, ellipsis, bullet and check mark all survive — only that one character is affected. The fix was to use ASCII `->`.

**Dates rendered differently on each side of the diff.** `getOriginal()` returns cast attributes, so `updated_at` came back as a `Carbon` and printed as `"2026-08-14T14:51:13.000000Z"`, while the "after" side read raw strings from the database and printed `2026-08-14 14:54:51`. The comparison was correct — only the display was inconsistent — so no test noticed. Switching to `getRawOriginal()` puts both sides in the same format.

Both are cosmetic, and both would have been the first thing a user saw.

## Tearing it down

The testbed is a throwaway. Delete the directory and nothing in the package is affected — the symlink points the other way.

To go back to the published package, drop the `repositories` entry from the app's `composer.json` and `composer update kstmostofa/laravel-backfill`.
