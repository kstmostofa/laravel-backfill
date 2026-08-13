<?php

namespace Kstmostofa\Backfill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $run_id
 * @property string|null $from_id
 * @property string|null $to_id
 * @property int $count
 * @property int $failed
 * @property int $duration_ms
 * @property int $attempts
 */
class BackfillRunBatch extends Model
{
    protected $table = 'backfill_run_batches';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'count' => 'integer',
        'failed' => 'integer',
        'duration_ms' => 'integer',
        'attempts' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('backfill.connection');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackfillRun::class, 'run_id');
    }
}
