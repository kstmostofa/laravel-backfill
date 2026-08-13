# Events

Eight events under `Kstmostofa\Backfill\Events`, dispatched by the runner.

| Event | When | Carries |
| --- | --- | --- |
| `BackfillStarted` | A run begins | `$run`, `$resumed` |
| `BackfillResumed` | A run continues from a cursor | `$run`, `$cursor` |
| `BatchProcessed` | Every committed batch | `$run`, `$outcome` |
| `RowFailed` | A row throws | `$run`, `$record`, `$recordId`, `$exception` |
| `BackfillPaused` | A run stops early | `$run`, `$reason`, `$message` |
| `BackfillCompleted` | A run finishes cleanly | `$run` |
| `BackfillFailed` | A run stops with an error | `$run`, `$exception` |
| `ThrottleEngaged` | The throttle changes pace | `$run`, `$decision` |

## Listening

```php
use Illuminate\Support\Facades\Event;
use Kstmostofa\Backfill\Events\BackfillCompleted;

Event::listen(BackfillCompleted::class, function (BackfillCompleted $event) {
    Log::info("Backfill {$event->run->backfill} finished", [
        'processed' => $event->run->processed_count,
        'failed' => $event->run->failed_count,
        'duration' => $event->run->started_at->diffForHumans($event->run->finished_at, true),
    ]);
});
```

::: warning `BatchProcessed` fires thousands of times
On an 8M row backfill with 1,000-row batches that is 8,000 events. Keep those listeners cheap and **do not queue them** — you would be dispatching 8,000 jobs to report on a job.
:::

## Telling pauses apart

`BackfillPaused` is the one worth branching on. It carries a `StopReason` and a `wasAutomatic()` helper:

```php
Event::listen(BackfillPaused::class, function (BackfillPaused $event) {
    if ($event->wasAutomatic()) {
        // The circuit breaker or the throttle stopped it. Somebody
        // should look before it starts again.
        PagerDuty::trigger($event->message);
    }

    // Otherwise a human pressed pause, or a slice of queued work
    // finished, or the worker got SIGTERM. Not news.
});
```

`wasAutomatic()` is true for `StopReason::CircuitBreaker` and `StopReason::Throttle`. The full set:

| `StopReason` | Meaning |
| --- | --- |
| `MaxBatches` | A `--max-batches` slice finished |
| `CircuitBreaker` | Failures looked systemic |
| `Throttle` | Replicas would not recover |
| `Signal` | `SIGTERM` or `SIGINT` |
| `Operator` | A human paused or cancelled it |

The same value is stored on the run as `meta.stop_code`, which is what [queue mode](/features/queue#when-the-chain-stops) uses to decide whether to chain another job.

## Reacting to failed rows

```php
Event::listen(RowFailed::class, function (RowFailed $event) {
    Sentry::captureException($event->exception, [
        'backfill' => $event->run->backfill,
        'record' => $event->recordId,
    ]);
});
```

The failure has already been recorded in `backfill_run_errors` by the time this fires; the event is for your own reporting. `$record` is the model itself, or a `stdClass` on the [un-hydrated fast path](/guide/writing-a-backfill#the-fast-path).

## Watching the throttle

```php
Event::listen(ThrottleEngaged::class, function (ThrottleEngaged $event) {
    Log::warning('Backfill throttled', [
        'lag' => $event->decision->lagSeconds,
        'sleep_ms' => $event->decision->sleepMs,
        'batch_size' => $event->decision->batchSize,
        'reason' => $event->decision->reason,
    ]);
});
```

Fires both when the throttle slows things down and when it gives up and pauses the run.

## Testing against events

```php
use Illuminate\Support\Facades\Event;

Event::fake([BatchProcessed::class]);

runBackfill(BackfillUserSlugs::class);

Event::assertDispatchedTimes(BatchProcessed::class, 3);
Event::assertDispatched(BatchProcessed::class, fn ($e) => $e->outcome->processed === 2);
```

## Built on top

[Notifications](/features/notifications) are listeners on `BackfillCompleted`, `BackfillFailed` and `BackfillPaused`. If the built-in ones do not fit, write your own listeners and leave `notifications.enabled` off.
