<?php

namespace Kstmostofa\Backfill\DryRun;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Support\MigrationGuard;
use Throwable;

/**
 * Runs a backfill for real against a handful of rows, inside a transaction
 * that is always rolled back, with every outbound side effect intercepted.
 *
 * The point is that the diffs are genuine. A dry run that only prints the
 * query tells you nothing about whether process() does what you think.
 */
class DryRunner
{
    public function perform(Backfill $backfill, ?int $samples = null): DryRunReport
    {
        if (MigrationGuard::inMigration()) {
            throw BackfillRefused::insideMigration($backfill->name());
        }

        if (! $backfill->guard()) {
            throw BackfillRefused::byGuard($backfill->name());
        }

        $samples ??= (int) config('backfill.dry_run.samples', 5);
        $key = $backfill->keyName();

        $scope = $this->scope($backfill);

        // On the un-hydrated path the cost is per batch, not per row: one bulk
        // UPDATE costs about the same whether it touches three rows or five
        // thousand. Timing three rows and multiplying by the row count reads
        // "1.8 hours" for a job that takes 75 seconds, so sample a whole batch
        // and scale by batch count instead.
        $timedRows = $backfill->hydrateModels
            ? $samples
            : max($samples, $backfill->resolvedBatchSize());

        $rows = $this->sampleRows($backfill, $key, $timedRows);

        // Explain the query the runner actually issues, cursor predicate and
        // all — without it the plan describes a query that never runs.
        $plan = QueryPlan::for($backfill->collection(), $key, $this->keyOf($rows->first(), $key));

        $recorder = new SideEffectRecorder;
        $recorder->install();

        $diffs = [];
        $elapsed = 0.0;
        $timed = 0;

        try {
            if ($rows->isNotEmpty()) {
                [$diffs, $elapsed, $timed] = $this->processInRolledBackTransaction($backfill, $rows, $key);
            }

            $sideEffects = $recorder->collect();
            $events = $recorder->events();
        } finally {
            $recorder->restore();
        }

        return new DryRunReport(
            backfill: $backfill->name(),
            scope: $scope,
            plan: $plan,
            // A whole batch was timed, but nobody wants five thousand diffs
            // printed at them.
            samples: array_slice($diffs, 0, $samples),
            sampleSeconds: $elapsed,
            sideEffects: $sideEffects ?? [],
            events: $events ?? [],
            batchSize: $backfill->resolvedBatchSize(),
            perRow: $backfill->hydrateModels,
            timedRows: $timed,
        );
    }

    /**
     * @param  mixed  $record
     */
    protected function keyOf($record, string $key)
    {
        if ($record === null) {
            return null;
        }

        return is_object($record) ? ($record->{$key} ?? null) : ($record[$key] ?? null);
    }

    /**
     * @return array{0: array<int, SampleDiff>, 1: float, 2: int}
     */
    protected function processInRolledBackTransaction(Backfill $backfill, Collection $rows, string $key): array
    {
        $diffs = [];
        $elapsed = 0.0;
        $timed = 0;

        try {
            $this->connection()->transaction(function () use ($backfill, $rows, $key, &$diffs, &$elapsed, &$timed) {
                $work = fn () => $backfill->hydrateModels
                    ? $this->processRows($backfill, $rows, $key)
                    : $this->processWholeBatch($backfill, $rows, $key);

                // Mirror the real run. Without this, observers fire during the
                // dry run but not during the run it is predicting, and the diff
                // shows changes that would never actually happen.
                [$diffs, $elapsed, $timed] = $backfill->withoutModelEvents
                    ? Model::withoutEvents($work)
                    : $work();

                // Nothing here is allowed to survive. Throwing is the only way
                // to guarantee the rollback even if the work committed
                // something nested.
                throw new RollbackSignal;
            });
        } catch (RollbackSignal) {
            // Expected: this is how the dry run stays dry.
        }

        return [$diffs, $elapsed, $timed];
    }

    /**
     * Only the process() calls are timed. Capturing the "after" state costs an
     * extra SELECT per row that a real run never issues, and counting it would
     * make every estimate roughly twice what the job actually takes.
     *
     * @return array{0: array<int, SampleDiff>, 1: float}
     */
    protected function processRows(Backfill $backfill, Collection $rows, string $key): array
    {
        $diffs = [];
        $timings = [];

        foreach ($rows as $record) {
            $id = (string) ($record->{$key} ?? '');

            // Raw values, not cast ones. getOriginal() would hand back Carbon
            // instances for dates while the "after" side reads raw strings
            // from the database, and the diff would then render the two halves
            // in different formats for a column that merely changed.
            $before = $record instanceof Model ? $record->getRawOriginal() : (array) $record;

            try {
                $startedAt = microtime(true);
                $backfill->process($record);
                $timings[] = microtime(true) - $startedAt;
            } catch (Throwable $e) {
                $diffs[] = new SampleDiff($id, [], $e->getMessage());

                continue;
            }

            $after = $this->currentState($backfill, $key, $record->{$key});

            $diffs[] = $after === null
                ? new SampleDiff($id, [], null, true)
                : new SampleDiff($id, $this->diff($before, $after));
        }

        // The first row pays for connection warm-up, query compilation and
        // booting the model — costs the other 8 million rows never see. With a
        // five-row sample that one row is most of the measurement, which is how
        // a three-minute job came to be advertised as half an hour.
        if (count($timings) >= 3) {
            array_shift($timings);
        }

        return [$diffs, array_sum($timings), count($timings)];
    }

    /**
     * @return array<int, SampleDiff>
     */
    protected function processWholeBatch(Backfill $backfill, Collection $rows, string $key): array
    {
        $ids = $rows->pluck($key)->all();
        $before = $this->rawState($backfill, $key, $ids);

        try {
            // As above, only the work itself is timed — the before/after reads
            // are the dry run's own overhead, not the job's.
            $startedAt = microtime(true);
            $backfill->processBatch($rows);
            $elapsed = microtime(true) - $startedAt;
        } catch (Throwable $e) {
            return [
                collect($ids)
                    ->map(fn ($id) => new SampleDiff((string) $id, [], $e->getMessage()))
                    ->all(),
                0.0,
                0,
            ];
        }

        $after = $this->rawState($backfill, $key, $ids);

        $diffs = collect($ids)->map(function ($id) use ($before, $after) {
            $key = (string) $id;

            return isset($after[$key])
                ? new SampleDiff($key, $this->diff($before[$key] ?? [], $after[$key]))
                : new SampleDiff($key, [], null, true);
        })->all();

        return [$diffs, $elapsed, count($ids)];
    }

    /**
     * The row as the database now holds it, inside the open transaction.
     */
    protected function currentState(Backfill $backfill, string $key, $id): ?array
    {
        $model = $backfill->collection()->getModel();

        $fresh = $model->newQueryWithoutScopes()
            ->where($key, $id)
            ->first();

        return $fresh?->getAttributes();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function rawState(Backfill $backfill, string $key, array $ids): array
    {
        $model = $backfill->collection()->getModel();

        return collect(
            $model->newQueryWithoutScopes()->toBase()->whereIn($key, $ids)->get()
        )
            ->mapWithKeys(fn ($row) => [(string) ((array) $row)[$key] => (array) $row])
            ->all();
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $column => $value) {
            $old = $before[$column] ?? null;

            if ($this->normalise($old) !== $this->normalise($value)) {
                $changes[$column] = ['from' => $old, 'to' => $value];
            }
        }

        return $changes;
    }

    protected function normalise(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => json_encode($value),
        };
    }

    protected function sampleRows(Backfill $backfill, string $key, int $limit): Collection
    {
        $query = $backfill->collection()->reorder()->orderBy($key)->limit($limit);

        return $backfill->hydrateModels
            ? $query->get()
            : collect($query->toBase()->get());
    }

    protected function scope(Backfill $backfill): ?int
    {
        try {
            return $backfill->collection()->toBase()->count();
        } catch (Throwable) {
            return null;
        }
    }

    protected function connection()
    {
        return DB::connection(config('backfill.connection'));
    }
}
