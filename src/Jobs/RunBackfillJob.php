<?php

namespace Kstmostofa\Backfill\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Enums\StopReason;
use Kstmostofa\Backfill\Events\BackfillFailed;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\LockManager;
use Kstmostofa\Backfill\Runner\RunOptions;
use Throwable;

/**
 * Runs a slice of a backfill, then queues itself again for the next slice.
 *
 * Chaining rather than one long-lived job is what makes queued backfills
 * survive a deploy: each job is short, so a worker restart costs at most one
 * batch, and the next job picks up from the committed cursor.
 */
class RunBackfillJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Seconds before the worker kills this job. Set from
     * backfill.queue.timeout at dispatch, and kept below the queue
     * connection's retry_after so a slow job is never handed to a second
     * worker while the first is still on it.
     */
    public int $timeout = 85;

    /**
     * A job killed for running too long is a failure worth hearing about, not
     * one to retry silently.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $backfill,
        public readonly ?int $batchesPerJob = null,
        public readonly ?int $batchSize = null,
        public readonly ?int $sleepMs = null,
        public readonly bool $force = false,
        public readonly ?string $startedBy = null,
        /** @var array<string, mixed> */
        public readonly array $parameters = [],
        public readonly ?string $tenant = null,
    ) {
        $this->timeout = max(1, (int) config('backfill.queue.timeout', 85));
    }

    public function handle(BackfillRegistry $registry, BackfillRunner $runner): void
    {
        $backfill = $registry->find($this->backfill);

        try {
            $run = $runner->run($backfill, new RunOptions(
                batchSize: $this->batchSize,
                sleepMs: $this->sleepMs,
                maxBatches: $this->batchesPerJob(),
                maxSeconds: $this->maxSeconds(),
                startedBy: $this->startedBy ?? 'queue',
                force: $this->force,
                parameters: $this->parameters,
                tenant: $this->tenant,
            ));
        } catch (BackfillAlreadyRunning) {
            // Another worker already has it. Nothing to do and nothing wrong.
            return;
        } catch (BackfillRefused $e) {
            // A guard said no — the table grew past the ceiling, or a freeze
            // window opened between dispatch and execution. That is a policy
            // decision, not a crash, so log it rather than filling failed_jobs
            // with stack traces that read like something broke.
            Log::warning('Backfill refused: '.$e->getMessage(), [
                'backfill' => $this->backfill,
                'tenant' => $this->tenant,
            ]);

            return;
        }

        if ($this->shouldChain($run)) {
            $this->chain();
        }
    }

    /**
     * Only continue when the run stopped because this job's slice was done.
     * A pause from the circuit breaker, the throttle or an operator means
     * somebody should look before it starts again.
     */
    protected function shouldChain($run): bool
    {
        if ($run->status !== RunStatus::Paused) {
            return false;
        }

        $code = $run->meta['stop_code'] ?? null;

        return $code !== null
            && StopReason::tryFrom($code)?->isAutomaticallyResumable() === true;
    }

    protected function chain(): void
    {
        static::dispatch(
            $this->backfill,
            $this->batchesPerJob,
            $this->batchSize,
            $this->sleepMs,
            $this->force,
            $this->startedBy,
            $this->parameters,
            $this->tenant,
        )
            ->onConnection($this->connection ?? config('backfill.queue.connection'))
            ->onQueue($this->queue ?? config('backfill.queue.queue'));
    }

    protected function batchesPerJob(): int
    {
        return $this->batchesPerJob ?? (int) config('backfill.queue.batches_per_job', 25);
    }

    /**
     * Stop the slice early when the batches turn out slower than expected, so
     * a job finishes on its own long before the worker would kill it.
     */
    protected function maxSeconds(): ?int
    {
        $seconds = config('backfill.queue.max_seconds_per_job', 45);

        return $seconds === null ? null : max(1, (int) $seconds);
    }

    /**
     * Called when the worker gives up on this job — most often because it ran
     * past its timeout and was killed. Without this, the run would sit at
     * "running" until its heartbeat went cold, the chain would have quietly
     * stopped, and nobody would be told.
     */
    public function failed(?Throwable $exception = null): void
    {
        $run = BackfillRun::query()
            ->where('backfill', $this->backfill)
            ->when($this->tenant === null,
                fn ($query) => $query->whereNull('tenant'),
                fn ($query) => $query->where('tenant', $this->tenant),
            )
            ->latest('id')
            ->first();

        if (! $run || $run->status !== RunStatus::Running) {
            return;
        }

        $exception ??= new \RuntimeException('The queued backfill job failed.');

        $run->forceFill([
            'status' => RunStatus::Interrupted,
            'error' => $exception->getMessage(),
            'heartbeat_at' => now(),
        ])->save();

        // The process is about to die, so the runner's own finally block will
        // never release the lock. Hand it back now rather than making the next
        // attempt wait for the heartbeat to go stale.
        $locks = app(LockManager::class);
        $key = $this->tenant === null ? $this->backfill : $this->backfill.':'.$this->tenant;

        if ($locks->heldBy($key, $locks->owner())) {
            $locks->release($key);
        }

        BackfillFailed::dispatch($run, $exception);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['backfill', 'backfill:'.$this->backfill];
    }
}
