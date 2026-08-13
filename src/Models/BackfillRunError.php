<?php

namespace Kstmostofa\Backfill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property string|null $record_id
 * @property string $exception_class
 * @property string $message
 * @property string|null $trace
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class BackfillRunError extends Model
{
    protected $table = 'backfill_run_errors';

    protected $guarded = [];

    protected $casts = [
        'attempts' => 'integer',
        'resolved_at' => 'datetime',
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
