<?php

namespace Kstmostofa\Backfill\Tests\Fixtures\Backfills;

use Illuminate\Database\Eloquent\Builder;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Parameters\Parameter;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * The shape the operator panel exists for: support staff paste a list of ids
 * and press Run, without a shell or a developer.
 */
class BackfillOrderRefunds extends Backfill
{
    public int $batchSize = 2;

    public bool $operatorRunnable = true;

    public static array $processed = [];

    public function description(): string
    {
        return 'Re-issue refund receipts';
    }

    public function parameters(): array
    {
        return [
            Parameter::ids('user_ids', 'User IDs')
                ->required()
                ->max(5)
                ->help('Paste the ids from the spreadsheet.'),

            Parameter::select('tone', ['formal' => 'Formal', 'friendly' => 'Friendly'], 'Wording')
                ->default('formal'),

            Parameter::boolean('notify', 'Email the customer'),
        ];
    }

    public function collection(): Builder
    {
        return User::query()
            ->whereNull('slug')
            ->whereIn('id', $this->parameter('user_ids', []));
    }

    public function process($record): void
    {
        static::$processed[] = $record->id;

        $record->forceFill([
            'slug' => $this->parameter('tone', 'formal').'-'.$record->id,
            'process_count' => $record->process_count + 1,
        ])->save();
    }

    public static function reset(): void
    {
        static::$processed = [];
    }
}
