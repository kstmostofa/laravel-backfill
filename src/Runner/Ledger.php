<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records which rows a backfill has already acted on, for work the database
 * cannot undo.
 *
 * The per-batch transaction makes a redo safe for database writes: a rolled-back
 * batch never happened. An email does not roll back. So for those backfills a
 * row is *claimed* in its own committed transaction before process() runs, and
 * *confirmed* afterwards.
 *
 * That ordering is a deliberate trade. A crash between the claim and the work
 * leaves a row unprocessed rather than an email sent twice, and the unconfirmed
 * claim is left behind so an operator can see exactly which rows are in doubt.
 * Sending nothing is recoverable; sending twice is not.
 */
class Ledger
{
    /**
     * Which of these keys have already been claimed or processed.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public function seen(string $backfill, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->table()
            ->where('backfill', $backfill)
            ->whereIn('record_id', array_map(strval(...), $keys))
            ->pluck('record_id')
            ->all();
    }

    /**
     * Stake a claim before doing anything the database cannot take back.
     * Returns false when another process got there first.
     */
    public function claim(string $backfill, string $recordId, ?int $runId): bool
    {
        return $this->table()->insertOrIgnore([
            'backfill' => $backfill,
            'record_id' => $recordId,
            'run_id' => $runId,
            'claimed_at' => now(),
            'processed_at' => null,
        ]) === 1;
    }

    public function confirm(string $backfill, string $recordId): void
    {
        $this->table()
            ->where('backfill', $backfill)
            ->where('record_id', $recordId)
            ->update(['processed_at' => now()]);
    }

    /**
     * Give a claim back after work that definitely did not happen, so the row
     * can be retried. Only safe when process() failed before any side effect.
     */
    public function release(string $backfill, string $recordId): void
    {
        $this->table()
            ->where('backfill', $backfill)
            ->where('record_id', $recordId)
            ->whereNull('processed_at')
            ->delete();
    }

    public function unconfirmedCount(string $backfill): int
    {
        return $this->table()
            ->where('backfill', $backfill)
            ->whereNull('processed_at')
            ->count();
    }

    /**
     * @return Collection<int, object>
     */
    public function unconfirmed(string $backfill, int $limit = 25): Collection
    {
        return collect($this->table()
            ->where('backfill', $backfill)
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get());
    }

    public function forget(string $backfill): void
    {
        $this->table()->where('backfill', $backfill)->delete();
    }

    protected function table()
    {
        return DB::connection(config('backfill.connection'))->table('backfill_ledger');
    }
}
