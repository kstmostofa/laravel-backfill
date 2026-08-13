<?php

namespace Kstmostofa\Backfill\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $backfill
 * @property string $record_id
 * @property int|null $run_id
 * @property \Illuminate\Support\Carbon|null $claimed_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
class BackfillLedgerEntry extends Model
{
    protected $table = 'backfill_ledger';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('backfill.connection');
    }

    /**
     * Claimed but never confirmed — the process died between marking the row
     * and finishing the work, so nobody knows whether the email went out.
     */
    public function scopeUnconfirmed($query)
    {
        return $query->whereNull('processed_at');
    }
}
