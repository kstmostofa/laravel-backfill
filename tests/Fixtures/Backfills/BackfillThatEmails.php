<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use RuntimeException;

/**
 * A backfill whose work escapes the database — the case a rolled-back batch
 * cannot undo, and the reason ledger mode exists.
 *
 * The collection is deliberately NOT self-excluding: nothing about a sent email
 * changes the row, so re-running would happily send it again.
 */
class BackfillThatEmails extends Backfill
{
    public int $batchSize = 2;

    public bool $ledger = true;

    public bool $externalSideEffects = true;

    /** Every "email" this fixture has sent, in order. */
    public static array $sent = [];

    /** Ids that should throw after the send, to mimic a partial failure. */
    public static array $failAfterSending = [];

    public function collection(): Builder
    {
        return User::query();
    }

    public function process($record): void
    {
        static::$sent[] = $record->id;

        if (in_array($record->id, static::$failAfterSending, true)) {
            throw new RuntimeException("Failed after emailing {$record->id}.");
        }

        $record->forceFill(['process_count' => $record->process_count + 1])->save();
    }

    public static function reset(): void
    {
        static::$sent = [];
        static::$failAfterSending = [];
    }
}
