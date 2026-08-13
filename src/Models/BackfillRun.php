<?php

namespace Kstmostofa\Backfill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kstmostofa\Backfill\Enums\RunStatus;

/**
 * @property int $id
 * @property string $backfill
 * @property string $backfill_class
 * @property RunStatus $status
 * @property string|null $cursor
 * @property string $key_name
 * @property int|null $total_estimate
 * @property int $processed_count
 * @property int $failed_count
 * @property int $skipped_count
 * @property int $batch_count
 * @property int $batch_size
 * @property int $sleep_ms
 * @property bool $dry_run
 * @property string|null $started_by
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property string|null $error
 * @property array|null $meta
 */
class BackfillRun extends Model
{
    protected $table = 'backfill_runs';

    protected $guarded = [];

    protected $casts = [
        'status' => RunStatus::class,
        'total_estimate' => 'integer',
        'processed_count' => 'integer',
        'failed_count' => 'integer',
        'skipped_count' => 'integer',
        'batch_count' => 'integer',
        'batch_size' => 'integer',
        'sleep_ms' => 'integer',
        'dry_run' => 'boolean',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('backfill.connection');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(BackfillRunError::class, 'run_id');
    }

    /**
     * A run is stale when it claims to be running but stopped checking in —
     * the signature of a hard kill.
     */
    public function isStale(): bool
    {
        if ($this->status !== RunStatus::Running) {
            return false;
        }

        $staleAfter = (int) config('backfill.stale_after', 120);

        return $this->heartbeat_at === null
            || $this->heartbeat_at->addSeconds($staleAfter)->isPast();
    }

    public function progressPercent(): ?float
    {
        if (! $this->total_estimate) {
            return null;
        }

        $done = $this->processed_count + $this->failed_count + $this->skipped_count;

        return min(100.0, round($done / $this->total_estimate * 100, 1));
    }

    public function throughputPerSecond(): ?float
    {
        $end = $this->finished_at ?? $this->heartbeat_at ?? now();

        if (! $this->started_at) {
            return null;
        }

        $seconds = max(1, $this->started_at->diffInSeconds($end));

        return round($this->processed_count / $seconds, 1);
    }
}
