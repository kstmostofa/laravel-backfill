<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Exceptions\BackfillAlreadyRunning;

/**
 * A row in backfill_locks is the run lock. Acquiring is an insert against a
 * unique index, which gives identical mutual-exclusion guarantees on MySQL,
 * PostgreSQL and SQLite — unlike a partial unique index, which MySQL lacks.
 */
class LockManager
{
    public function acquire(string $backfill, ?int $runId = null): void
    {
        if ($this->insert($backfill, $runId)) {
            return;
        }

        // Someone holds it. If their heartbeat has gone cold they were killed,
        // so the lock is ours to take.
        $existing = $this->existing($backfill);

        if ($existing && $this->isStale($existing)) {
            $this->table()
                ->where('backfill', $backfill)
                ->where(function ($query) use ($existing) {
                    $query->where('heartbeat_at', $existing->heartbeat_at)
                        ->orWhereNull('heartbeat_at');
                })
                ->delete();

            if ($this->insert($backfill, $runId)) {
                return;
            }

            $existing = $this->existing($backfill);
        }

        throw BackfillAlreadyRunning::make(
            $backfill,
            $existing->owner ?? null,
            isset($existing->acquired_at) && $existing->acquired_at
                ? Carbon::parse($existing->acquired_at)
                : null
        );
    }

    /**
     * Record which run owns the lock, once the run row exists.
     */
    public function attachRun(string $backfill, int $runId): void
    {
        $this->table()->where('backfill', $backfill)->update(['run_id' => $runId]);
    }

    public function heartbeat(string $backfill): void
    {
        $this->table()->where('backfill', $backfill)->update(['heartbeat_at' => now()]);
    }

    public function release(string $backfill): void
    {
        $this->table()->where('backfill', $backfill)->delete();
    }

    public function isHeld(string $backfill): bool
    {
        return $this->table()->where('backfill', $backfill)->exists();
    }

    public function owner(): string
    {
        return gethostname().':'.getmypid();
    }

    protected function insert(string $backfill, ?int $runId): bool
    {
        $now = now();

        return $this->table()->insertOrIgnore([
            'backfill' => $backfill,
            'run_id' => $runId,
            'owner' => $this->owner(),
            'acquired_at' => $now,
            'heartbeat_at' => $now,
        ]) === 1;
    }

    protected function existing(string $backfill): ?object
    {
        return $this->table()->where('backfill', $backfill)->first();
    }

    protected function isStale(object $lock): bool
    {
        if (empty($lock->heartbeat_at)) {
            return true;
        }

        $staleAfter = (int) config('backfill.stale_after', 120);

        return Carbon::parse($lock->heartbeat_at)->addSeconds($staleAfter)->isPast();
    }

    protected function table()
    {
        return DB::connection(config('backfill.connection'))->table('backfill_locks');
    }
}
