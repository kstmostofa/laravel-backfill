# Running on the queue

```bash
php artisan backfill:run user-slugs --queue
```

For anything measured in hours, this is how you should run it. Nothing depends on your terminal staying open, and a deploy costs one batch instead of the whole run.

## Chained short jobs, not one long one

The obvious design is one job that runs the entire backfill. It is also the wrong one: a worker restart kills it, and everything since the last commit is lost while nothing re-queues it.

Instead, each job runs a slice and then queues the next one. A slice ends after 25 batches or 45 seconds, whichever comes first:

```php
'queue' => [
    'connection' => null,
    'queue' => null,
    'batches_per_job' => 25,
    'max_seconds_per_job' => 45,
    'timeout' => 85,
],
```

```bash
php artisan backfill:run user-slugs --queue --batches-per-job=50
```

Short jobs mean a worker restart mid-deploy costs at most the batch in flight, and the next job picks up from the committed cursor without anyone intervening.

## Fitting inside the worker timeout

A batch count alone cannot promise a short job. Twenty-five batches of a `process()` that calls an API can take ten minutes, and the worker kills any job that outlives its timeout.

So each slice also has a time limit. Once a batch commits after `max_seconds_per_job`, the job stops and chains the next one. The job's own `timeout` is set from config, and `failOnTimeout` is on.

Keep the numbers in this order:

```
max_seconds_per_job  +  one slow batch  <  timeout  <  retry_after
```

`retry_after` belongs to your queue connection in `config/queue.php` and defaults to 90 seconds. A job that outlives it is handed to a second worker while the first is still running. The run lock stops the second one doing any harm, but it is noise you do not want.

If the worker does kill a job, its `failed()` hook marks the run `interrupted`, releases the lock, and fires `BackfillFailed`, so a notification goes out. Resume it once you have raised the timeout or shrunk the batch.

## When the chain stops

This is the part that needed the most care. After a job's slice ends, the run is paused — but "paused" covers two very different situations, and chaining blindly is as wrong as never chaining.

Every pause records a machine-readable `stop_code` alongside the human sentence:

| `stop_code` | Meaning | Chains? |
| --- | --- | --- |
| `max_batches` | This job's slice finished | **Yes** |
| `time_limit` | This job's slice ran out of time | **Yes** |
| `circuit_breaker` | Failures look systemic | No |
| `throttle` | Replicas will not recover | No |
| `signal` | The worker got `SIGTERM` | No |
| `operator` | A human pressed pause | No |

A circuit-breaker pause would only trip again on the next job, so chaining past it means an infinite loop of failing jobs. Those pauses stop the chain and wait for someone to look. The run stays resumable, so once the cause is fixed a plain `backfill:resume` — or another `--queue` — carries on.

The chain also stops, obviously, when the backfill completes.

## Two workers, one backfill

The [run lock](/safety/invariant) means only one worker can run a given backfill at a time. If a second job starts while another worker holds the lock, it exits quietly — no exception, no failed job, nothing in the log. The other worker has it, and that is fine.

This makes queue mode safe on a multi-worker setup without any coordination on your part.

## Deploys

A deploy usually stops workers with `SIGTERM`. The runner traps it, finishes the batch in flight, commits its cursor, and marks the run paused with `stop_code: signal`.

The worker's own `SIGTERM` handler still runs alongside the runner's, and is put back when the run ends. The worker therefore exits once the job returns, exactly as it would without a backfill in it.

That does mean the chain stops on a deploy. Resume it afterwards:

```bash
php artisan backfill:resume user-slugs
```

Or queue it again — resuming and queueing both continue from the committed cursor.

## Horizon

Jobs are tagged, so they group sensibly in Horizon:

```php
['backfill', 'backfill:user-slugs']
```

Give backfills their own queue if you do not want a long chain competing with user-facing work:

```php
'queue' => [
    'connection' => 'redis',
    'queue' => 'backfills',
    'batches_per_job' => 25,
    'max_seconds_per_job' => 45,
    'timeout' => 85,
],
```

## Dispatching it yourself

```php
use Kstmostofa\Backfill\Jobs\RunBackfillJob;

RunBackfillJob::dispatch(
    backfill: 'user-slugs',
    batchesPerJob: 25,
    batchSize: 500,
    sleepMs: 100,
    force: false,
    startedBy: 'scheduler',
    parameters: ['order_ids' => [1, 2, 3]],
    tenant: 'acme',
)->onQueue('backfills');
```

Useful from a scheduled command, or to kick a backfill off from application code once some other condition is met.

## Watching it

`backfill:status` and the [dashboard](/features/dashboard) work exactly the same for a queued run. The [Pulse card](/features/pulse) is a good place to keep an eye on one that will be going for hours.
