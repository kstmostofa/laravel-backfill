<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use RuntimeException;

class BackfillWithFailingRow extends Backfill
{
    public int $batchSize = 2;

    /** Rows whose id is in here throw. */
    public static array $poisoned = [3];

    public static array $failedRows = [];

    public function collection(): Builder
    {
        return User::query()->whereNull('slug');
    }

    public function process($record): void
    {
        if (in_array($record->id, static::$poisoned, true)) {
            throw new RuntimeException("Row {$record->id} is poisoned.");
        }

        $record->forceFill([
            'slug' => Str::slug($record->name),
            'process_count' => $record->process_count + 1,
        ])->save();
    }

    public function onRowFailed($record, \Throwable $e): void
    {
        static::$failedRows[] = $record->id;
    }

    public static function reset(): void
    {
        static::$poisoned = [3];
        static::$failedRows = [];
    }
}
