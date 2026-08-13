<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

/**
 * The bug this package exists to prevent.
 *
 * Laravel's chunk() paginates with LIMIT/OFFSET. When the work makes a row stop
 * matching the query — which is exactly what a self-excluding backfill does —
 * the result set shrinks under the cursor and every OFFSET jump skips as many
 * rows as the last batch removed. Roughly half the table is silently missed.
 */
it('does not skip rows when processing removes them from the collection', function () {
    User::seedUnslugged(10);

    $run = runBackfill(BackfillUserSlugs::class);

    expect(User::whereNull('slug')->count())->toBe(0)
        ->and($run->processed_count)->toBe(10);
});

it('demonstrates the offset bug that keyset pagination avoids', function () {
    User::seedUnslugged(10);

    // Reproduce what chunk() does: page through with LIMIT/OFFSET while the
    // rows being processed leave the result set.
    $processed = 0;
    $page = 0;

    while (true) {
        $rows = User::query()->whereNull('slug')
            ->orderBy('id')
            ->offset($page * 2)
            ->limit(2)
            ->get();

        if ($rows->isEmpty()) {
            break;
        }

        foreach ($rows as $row) {
            $row->forceFill(['slug' => 'x'])->save();
            $processed++;
        }

        $page++;
    }

    // The offset walk misses rows; the keyset runner above does not.
    expect($processed)->toBeLessThan(10)
        ->and(User::whereNull('slug')->count())->toBeGreaterThan(0);
});

it('ignores any ordering the collection came with', function () {
    User::seedUnslugged(6);

    $backfill = new class extends Backfill
    {
        public int $batchSize = 2;

        public function collection(): Builder
        {
            // A descending order here would break keyset pagination outright
            // if the runner did not reorder.
            return User::query()->whereNull('slug')->orderByDesc('id');
        }

        public function process($record): void
        {
            $record->forceFill(['slug' => 'ok', 'process_count' => $record->process_count + 1])->save();
        }
    };

    $run = runBackfill($backfill);

    expect($run->processed_count)->toBe(6)
        ->and(User::whereNull('slug')->count())->toBe(0)
        ->and(User::where('process_count', 1)->count())->toBe(6);
});

it('walks a string primary key in order', function () {
    foreach (['a1', 'b2', 'c3', 'd4', 'e5'] as $uid) {
        DB::table('bf_docs')->insert(['uid' => $uid, 'body' => 'x']);
    }

    $backfill = new class extends Backfill
    {
        public int $batchSize = 2;

        public function collection(): Builder
        {
            return \Kstmostofa\Backfill\Tests\Fixtures\Doc::query()->where('done', 0);
        }

        public function process($record): void
        {
            $record->forceFill(['done' => 1])->save();
        }
    };

    $run = runBackfill($backfill);

    expect($run->processed_count)->toBe(5)
        ->and($run->cursor)->toBe('e5')
        ->and(DB::table('bf_docs')->where('done', 0)->count())->toBe(0);
});
