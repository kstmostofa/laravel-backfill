<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Models\BackfillRunBatch;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Support\MigrationGuard;
use Kstmostofa\Backfill\Support\TransientFailure;
use Throwable;

class BackfillRunner
{
    /** Recent batch durations, for the rolling median the throttle compares against. */
    protected array $durations = [];

    public function __construct(
        protected LockManager $locks,
        protected ShutdownSignals $signals,
        protected ConnectionTimeouts $timeouts,
        protected CircuitBreaker $breaker,
        protected Throttle $throttle,
        protected ProductionGuards $guards,
    ) {}

    public function run(Backfill $backfill, ?RunOptions $options = null): BackfillRun
    {
        $options ??= new RunOptions;

        if (MigrationGuard::inMigration()) {
            throw BackfillRefused::insideMigration($backfill->name());
        }

        if (! $backfill->guard()) {
            throw BackfillRefused::byGuard($backfill->name());
        }

        // Counting the rows is the expensive part on the tables this package
        // exists for, so it happens at most once and only when something
        // actually needs the number.
        $estimate = null;
        $counted = false;

        $rowCount = function () use (&$estimate, &$counted, $backfill, $options) {
            if (! $counted) {
                $estimate = $options->withoutEstimate ? null : $this->estimate($backfill);
                $counted = true;
            }

            return $estimate;
        };

        $this->guards->check(
            $backfill,
            $this->guards->needsEstimate($options->force) ? $rowCount() : null,
            $options->force,
        );

        // The lock comes before the run row, so losing the race to another
        // process leaves no half-started run behind to confuse the next resume.
        $this->locks->acquire($backfill->name());

        $run = null;
        $this->durations = [];

        try {
            $run = $this->resolveRun($backfill, $options, $rowCount);

            $this->locks->attachRun($backfill->name(), $run->id);

            $this->signals->listen();
            $this->applyTimeouts();

            $run->forceFill([
                'status' => RunStatus::Running,
                'started_at' => $run->started_at ?? now(),
                'heartbeat_at' => now(),
                'finished_at' => null,
                'error' => null,
            ])->save();

            $backfill->beforeRun($run);

            $backfill->withoutModelEvents
                ? Model::withoutEvents(fn () => $this->loop($backfill, $run, $options))
                : $this->loop($backfill, $run, $options);

            if ($run->status === RunStatus::Running) {
                $this->finish($run, RunStatus::Completed);
            }

            $backfill->afterRun($run);
        } catch (Throwable $e) {
            // The cursor is only ever advanced by a committed batch, so
            // whatever happened here, resuming picks up exactly where the
            // last successful batch left off.
            if ($run) {
                $run->refresh();
                $this->finish($run, RunStatus::Failed, $e->getMessage());
            }

            throw $e;
        } finally {
            $this->timeouts->reset($this->connection());
            $this->signals->release();
            $this->locks->release($backfill->name());
        }

        return $run->refresh();
    }

    protected function loop(Backfill $backfill, BackfillRun $run, RunOptions $options): void
    {
        $key = $run->key_name;
        $cursor = $this->castCursor($run->cursor, $backfill, $key);
        $batches = 0;

        // Kept local: the throttle may shrink these for a while, and the run
        // row should still record what the operator actually asked for.
        $batchSize = $run->batch_size;
        $sleepMs = $run->sleep_ms;

        // Counted per session, not per run. A run that tripped the breaker,
        // got fixed and was resumed must be judged on what happens now, not on
        // the failures that prompted the fix.
        $sessionProcessed = 0;
        $sessionFailed = 0;

        while (true) {
            $rows = $this->fetchBatch($backfill, $key, $cursor, $batchSize);

            if ($rows->isEmpty()) {
                return;
            }

            $firstKey = $this->keyOf($rows->first(), $key);
            $lastKey = $this->keyOf($rows->last(), $key);

            $backfill->beforeBatch($rows, $run);

            $outcome = $this->runBatch($backfill, $run, $rows, $key, $lastKey);

            $this->recordBatch($run, $outcome, $firstKey, $lastKey);
            $this->rememberDuration($outcome->durationMs);

            $cursor = $lastKey;
            $batches++;
            $sessionProcessed += $outcome->processed;
            $sessionFailed += $outcome->failed;

            if ($options->onBatch) {
                ($options->onBatch)($run, $rows->count());
            }

            if ($this->breaker->shouldTrip($sessionProcessed, $sessionFailed)) {
                $this->finish($run, RunStatus::Paused, reason: $this->breaker->reason(
                    $sessionProcessed,
                    $sessionFailed,
                    $run->backfill,
                ));

                return;
            }

            if ($this->shouldStop($run)) {
                return;
            }

            if ($options->maxBatches !== null && $batches >= $options->maxBatches) {
                $this->finish($run, RunStatus::Paused, reason: "Stopped after {$batches} batches as requested.");

                return;
            }

            $decision = $this->throttle->evaluate(
                $run->sleep_ms,
                $run->batch_size,
                $this->medianDuration(),
                $outcome->durationMs,
            );

            if ($decision->pause) {
                $this->finish($run, RunStatus::Paused, reason: $decision->reason);

                return;
            }

            $sleepMs = $decision->sleepMs;
            $batchSize = $decision->batchSize;

            if ($decision->engaged() && $options->onThrottle) {
                ($options->onThrottle)($decision);
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
    }

    /**
     * Run one batch, retrying only failures that mean "the database was busy".
     *
     * A deadlock or lock timeout leaves the batch rolled back cleanly, so
     * trying again usually works. A genuine bug fails identically every time,
     * and retrying it only holds locks longer to reach the same error.
     */
    protected function runBatch(Backfill $backfill, BackfillRun $run, Collection $rows, string $key, $lastKey): BatchOutcome
    {
        $maxRetries = (int) config('backfill.retry.max_batch_retries', 3);
        $baseDelay = (int) config('backfill.retry.base_delay_ms', 250);
        $attempt = 0;
        $startedAt = microtime(true);

        while (true) {
            $attempt++;

            try {
                $work = fn () => $this->processBatch($backfill, $run, $rows, $key, $lastKey);

                $outcome = $backfill->useTransactions
                    ? $this->connection()->transaction($work)
                    : $work();

                return $outcome->withAttempts($attempt, (int) round((microtime(true) - $startedAt) * 1000));
            } catch (Throwable $e) {
                if ($attempt > $maxRetries || ! TransientFailure::matches($e)) {
                    throw $e;
                }

                // The rolled-back attempt left the in-memory counters ahead of
                // what the database holds. Re-read before retrying, or the next
                // persist counts the same rows twice.
                $run->refresh();

                usleep($baseDelay * (2 ** ($attempt - 1)) * 1000);
            }
        }
    }

    /**
     * Everything in here runs inside one transaction: the row work, the error
     * records, and the cursor advance. They commit together or not at all, so
     * the cursor can never claim work that was rolled back.
     */
    protected function processBatch(Backfill $backfill, BackfillRun $run, Collection $rows, string $key, $lastKey): BatchOutcome
    {
        $processed = 0;
        $failed = 0;
        $failures = [];

        if ($backfill->hydrateModels) {
            foreach ($rows as $record) {
                try {
                    $this->processRow($backfill, $record);
                    $processed++;
                } catch (Throwable $e) {
                    // One poisoned row must not take down a run of 8M. A busy
                    // database is different: let it bubble so the whole batch
                    // is retried rather than marking good rows bad.
                    if (TransientFailure::matches($e)) {
                        throw $e;
                    }

                    $failed++;
                    $failures[] = [$record, $e];
                }
            }
        } else {
            $backfill->processBatch($rows);
            $processed = $rows->count();
        }

        $backfill->afterBatch($rows, $run);

        foreach ($failures as [$record, $e]) {
            $this->recordError($run, $this->keyOf($record, $key), $e);
            $backfill->onRowFailed($record, $e);
        }

        $this->persistProgress($run, $lastKey, $processed, $failed);

        return new BatchOutcome($processed, $failed);
    }

    /**
     * Each row gets its own savepoint when transactions are on. Without it a
     * single failure would poison the surrounding transaction on PostgreSQL
     * and take the whole batch — including the error records — with it.
     *
     * @param  mixed  $record
     */
    protected function processRow(Backfill $backfill, $record): void
    {
        if ($backfill->useTransactions) {
            $this->connection()->transaction(fn () => $backfill->process($record));

            return;
        }

        $backfill->process($record);
    }

    protected function fetchBatch(Backfill $backfill, string $key, $cursor, int $limit): Collection
    {
        $query = $backfill->collection();

        if ($cursor !== null) {
            $query->where($key, '>', $cursor);
        }

        // reorder() drops any ordering the collection came with — keyset
        // pagination is only correct when the sort matches the cursor column.
        $query->reorder()->orderBy($key)->limit($limit);

        return $backfill->hydrateModels
            ? $query->get()
            : collect($query->toBase()->get());
    }

    protected function persistProgress(BackfillRun $run, $lastKey, int $processed, int $failed): void
    {
        $run->forceFill([
            'cursor' => (string) $lastKey,
            'processed_count' => $run->processed_count + $processed,
            'failed_count' => $run->failed_count + $failed,
            'batch_count' => $run->batch_count + 1,
            'heartbeat_at' => now(),
        ])->save();

        $this->locks->heartbeat($run->backfill);
    }

    protected function recordBatch(BackfillRun $run, BatchOutcome $outcome, $firstKey, $lastKey): void
    {
        if (! config('backfill.record_batches', false)) {
            return;
        }

        BackfillRunBatch::create([
            'run_id' => $run->id,
            'from_id' => $firstKey === null ? null : (string) $firstKey,
            'to_id' => $lastKey === null ? null : (string) $lastKey,
            'count' => $outcome->processed + $outcome->failed,
            'failed' => $outcome->failed,
            'duration_ms' => $outcome->durationMs,
            'attempts' => $outcome->attempts,
            'created_at' => now(),
        ]);
    }

    protected function rememberDuration(int $durationMs): void
    {
        $this->durations[] = $durationMs;

        if (count($this->durations) > 20) {
            array_shift($this->durations);
        }
    }

    protected function medianDuration(): ?float
    {
        if ($this->durations === []) {
            return null;
        }

        $sorted = $this->durations;
        sort($sorted);
        $count = count($sorted);
        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($sorted[$middle - 1] + $sorted[$middle]) / 2
            : (float) $sorted[$middle];
    }

    protected function recordError(BackfillRun $run, $recordId, Throwable $e): void
    {
        BackfillRunError::create([
            'run_id' => $run->id,
            'record_id' => $recordId === null ? null : (string) $recordId,
            'exception_class' => $e::class,
            'message' => Str::limit($e->getMessage(), 60000),
            'trace' => $e->getTraceAsString(),
            'attempts' => 1,
        ]);
    }

    /**
     * Stop between batches when paused, cancelled, or asked to shut down.
     * Checked after the cursor commit so stopping is always clean.
     */
    protected function shouldStop(BackfillRun $run): bool
    {
        $status = $this->externalStatus($run);

        if ($status === RunStatus::Paused || $status === RunStatus::Cancelled) {
            $this->finish($run, $status);

            return true;
        }

        if ($this->signals->shouldStop()) {
            $this->finish($run, RunStatus::Paused, reason: 'Received a shutdown signal; stopped once the batch in flight had committed.');

            return true;
        }

        return false;
    }

    /**
     * Re-read status from the database — pause/cancel are written by another
     * process, so the in-memory model cannot see them.
     */
    protected function externalStatus(BackfillRun $run): ?RunStatus
    {
        $value = $run->newQuery()->whereKey($run->getKey())->value('status');

        return match (true) {
            $value === null => null,
            $value instanceof RunStatus => $value,
            default => RunStatus::from($value),
        };
    }

    protected function finish(BackfillRun $run, RunStatus $status, ?string $error = null, ?string $reason = null): void
    {
        $meta = $run->meta ?? [];

        if ($reason !== null) {
            $meta['stop_reason'] = $reason;
        }

        $run->forceFill([
            'status' => $status,
            'error' => $error,
            'meta' => $meta === [] ? null : $meta,
            'heartbeat_at' => now(),
            'finished_at' => $status === RunStatus::Paused ? null : now(),
        ])->save();
    }

    protected function applyTimeouts(): void
    {
        $this->timeouts->apply(
            $this->connection(),
            config('backfill.timeouts.statement'),
            config('backfill.timeouts.lock'),
        );
    }

    protected function resolveRun(Backfill $backfill, RunOptions $options, callable $rowCount): BackfillRun
    {
        if (! $options->fresh) {
            $existing = $this->resumableRun($backfill);

            if ($existing) {
                $existing->forceFill([
                    'batch_size' => $options->batchSize ?? $existing->batch_size,
                    'sleep_ms' => $options->sleepMs ?? $existing->sleep_ms,
                    'started_by' => $options->startedBy ?? $existing->started_by,
                ])->save();

                return $existing;
            }
        }

        $batchSize = $options->batchSize ?? $backfill->resolvedBatchSize();

        return BackfillRun::create([
            'backfill' => $backfill->name(),
            'backfill_class' => $backfill::class,
            'status' => RunStatus::Pending,
            'cursor' => null,
            'key_name' => $backfill->keyName(),
            'total_estimate' => $rowCount(),
            'batch_size' => $batchSize,
            'sleep_ms' => $options->sleepMs ?? $backfill->resolvedSleepMs(),
            'dry_run' => false,
            'started_by' => $options->startedBy,
            'heartbeat_at' => now(),
        ]);
    }

    /**
     * The most recent run worth continuing. A run left in `running` state with
     * a cold heartbeat was hard-killed; mark it interrupted and offer it back.
     */
    public function resumableRun(Backfill $backfill): ?BackfillRun
    {
        $latest = BackfillRun::query()
            ->where('backfill', $backfill->name())
            ->latest('id')
            ->first();

        if (! $latest) {
            return null;
        }

        if ($latest->isStale()) {
            $latest->forceFill(['status' => RunStatus::Interrupted])->save();
        }

        return $latest->status->isResumable() ? $latest : null;
    }

    public function estimate(Backfill $backfill): ?int
    {
        try {
            return $backfill->collection()->toBase()->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  mixed  $record
     */
    protected function keyOf($record, string $key)
    {
        if (\is_object($record)) {
            return $record->{$key} ?? null;
        }

        return \is_array($record) ? ($record[$key] ?? null) : null;
    }

    /**
     * Cursors are stored as strings so integer, UUID and ULID keys all survive
     * the round trip. Cast back before comparing so the database does not have
     * to coerce a string against an integer column.
     */
    protected function castCursor(?string $cursor, Backfill $backfill, string $key)
    {
        if ($cursor === null) {
            return null;
        }

        $model = $backfill->collection()->getModel();

        if ($key === $model->getKeyName()) {
            return $model->getKeyType() === 'int' ? (int) $cursor : $cursor;
        }

        return is_numeric($cursor) ? (int) $cursor : $cursor;
    }

    protected function connection()
    {
        return DB::connection(config('backfill.connection'));
    }
}
