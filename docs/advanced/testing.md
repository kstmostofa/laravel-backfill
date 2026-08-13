# Testing your backfills

A backfill is a one-off script that changes production data. It deserves a test more than most of your code, not less.

## The helper

```php
use Kstmostofa\Backfill\Testing\InteractsWithBackfills;

uses(InteractsWithBackfills::class);

it('slugs every user', function () {
    User::factory()->count(5)->create(['slug' => null]);

    $run = $this->runBackfill(BackfillUserSlugs::class);

    expect($run->processed_count)->toBe(5)
        ->and($run->failed_count)->toBe(0)
        ->and(User::whereNull('slug')->count())->toBe(0);
});
```

The trait works in Pest and PHPUnit alike.

## Use a small batch size

The helper defaults to a batch size of **2**, on purpose.

A test whose data fits in one batch never exercises pagination at all. The cursor is written once, `WHERE id > ?` is never evaluated, and every ordering bug you could have written sails through. Five rows across three batches finds them.

```php
$this->runBackfill(BackfillUserSlugs::class, ['batchSize' => 10]);
```

Any [run option](/reference/backfill-api#run-options) can be passed: `maxBatches`, `fresh`, `withoutEstimate`, `force`, `parameters`, `tenant`.

## Worth testing

**That it processes everything.** Especially with a self-excluding collection, where an offset-style bug would silently skip half the rows.

```php
expect(User::whereNull('slug')->count())->toBe(0);
```

**That it processes each row exactly once.** A counter column makes this cheap and catches double-application:

```php
expect(User::where('process_count', 1)->count())->toBe(5);
```

**That it is safe to run twice.**

```php
$this->runBackfill(BackfillUserSlugs::class);
$second = $this->runBackfill(BackfillUserSlugs::class, ['fresh' => true]);

expect($second->processed_count)->toBe(0);
```

**That it resumes.**

```php
$first = $this->runBackfill(BackfillUserSlugs::class, ['maxBatches' => 1]);
expect($first->status)->toBe(RunStatus::Paused);

$second = $this->runBackfill(BackfillUserSlugs::class);

expect($second->id)->toBe($first->id)
    ->and(User::where('process_count', 1)->count())->toBe(6);
```

**That a bad row does not stop it.**

```php
$run = $this->runBackfill(BackfillUserSlugs::class);

expect($run->status)->toBe(RunStatus::Completed)
    ->and($run->failed_count)->toBe(1)
    ->and(BackfillRunError::where('run_id', $run->id)->sole()->record_id)->toBe('3');
```

::: tip Make one failure a database error
A row failing with a PHP exception and a row failing with a constraint violation take different paths on PostgreSQL. See [transactions and savepoints](/safety/transactions#the-subtlety-worth-knowing).
:::

## Testing parameters

```php
$run = $this->runBackfill(BackfillOrderReceipts::class, [
    'parameters' => ['order_ids' => ['2', '4'], 'tone' => 'friendly'],
]);

expect($run->processed_count)->toBe(2);
```

Validate operator input the same way the panel does:

```php
use Kstmostofa\Backfill\Parameters\ParameterBag;

$result = ParameterBag::validate(new BackfillOrderReceipts, [
    'order_ids' => "1,\n2, 3",
]);

expect($result['errors'])->toBe([])
    ->and($result['values']['order_ids'])->toBe(['1', '2', '3']);
```

## Testing against events

```php
Event::fake([BatchProcessed::class, BackfillCompleted::class]);

$this->runBackfill(BackfillUserSlugs::class);

Event::assertDispatchedTimes(BatchProcessed::class, 3);
Event::assertDispatched(BackfillCompleted::class);
```

## Faking replication lag

Real lag cannot be produced on demand. Bind a monitor with scripted readings — see [throttling](/safety/throttling#testing-it).

## Turning off the guards

Tests run in the `testing` environment, so the confirmation prompt does not appear. Two things can still bite:

- A [row ceiling](/safety/guards#a-ceiling-on-how-much-it-will-touch) lower than your fixture count. Pass `['force' => true]`.
- The [circuit breaker](/safety/failures#a-systemic-problem), if you deliberately fail more than 25% of rows *and* seed more than 50 of them. Below `min_sample` it never trips, so most tests are unaffected.

## The one to copy

If your backfill matters, port the chaos test. Fork, have the child kill itself mid-batch, resume, and assert the end state matches an uninterrupted control run. The package's own version is in `tests/Chaos/HardKillTest.php` and needs the `pcntl` and `posix` extensions.
