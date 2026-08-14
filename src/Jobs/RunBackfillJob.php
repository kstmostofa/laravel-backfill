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
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\RunOptions;

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
    ) {}

    public function handle(BackfillRegistry $registry, BackfillRunner $runner): void
    {
        $backfill = $registry->find($this->backfill);

        try {
            $run = $runner->run($backfill, new RunOptions(
                batchSize: $this->batchSize,
                sleepMs: $this->sleepMs,
                maxBatches: $this->batchesPerJob(),
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
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['backfill', 'backfill:'.$this->backfill];
    }
}
