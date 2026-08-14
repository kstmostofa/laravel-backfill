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
        $rows = $this->sampleRows($backfill, $key, $samples);

        // Explain the query the runner actually issues, cursor predicate and
        // all — without it the plan describes a query that never runs.
        $plan = QueryPlan::for($backfill->collection(), $key, $this->keyOf($rows->first(), $key));

        $recorder = new SideEffectRecorder;
        $recorder->install();

        $diffs = [];
        $elapsed = 0.0;

        try {
            if ($rows->isNotEmpty()) {
                [$diffs, $elapsed] = $this->processInRolledBackTransaction($backfill, $rows, $key);
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
            samples: $diffs,
            sampleSeconds: $elapsed,
            sideEffects: $sideEffects ?? [],
            events: $events ?? [],
            batchSize: $backfill->resolvedBatchSize(),
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
     * @return array{0: array<int, SampleDiff>, 1: float}
     */
    protected function processInRolledBackTransaction(Backfill $backfill, Collection $rows, string $key): array
    {
        $diffs = [];
        $startedAt = microtime(true);
        $elapsed = 0.0;

        try {
            $this->connection()->transaction(function () use ($backfill, $rows, $key, &$diffs, $startedAt, &$elapsed) {
                $diffs = $backfill->hydrateModels
                    ? $this->processRows($backfill, $rows, $key)
                    : $this->processWholeBatch($backfill, $rows, $key);

                $elapsed = microtime(true) - $startedAt;

                // Nothing here is allowed to survive. Throwing is the only way
                // to guarantee the rollback even if the work committed
                // something nested.
                throw new RollbackSignal;
            });
        } catch (RollbackSignal) {
            // Expected: this is how the dry run stays dry.
        }

        return [$diffs, $elapsed];
    }

    /**
     * @return array<int, SampleDiff>
     */
    protected function processRows(Backfill $backfill, Collection $rows, string $key): array
    {
        $diffs = [];

        foreach ($rows as $record) {
            $id = (string) ($record->{$key} ?? '');

            // Raw values, not cast ones. getOriginal() would hand back Carbon
            // instances for dates while the "after" side reads raw strings
            // from the database, and the diff would then render the two halves
            // in different formats for a column that merely changed.
            $before = $record instanceof Model ? $record->getRawOriginal() : (array) $record;

            try {
                $backfill->process($record);
            } catch (Throwable $e) {
                $diffs[] = new SampleDiff($id, [], $e->getMessage());

                continue;
            }

            $after = $this->currentState($backfill, $key, $record->{$key});

            $diffs[] = $after === null
                ? new SampleDiff($id, [], null, true)
                : new SampleDiff($id, $this->diff($before, $after));
        }

        return $diffs;
    }

    /**
     * @return array<int, SampleDiff>
     */
    protected function processWholeBatch(Backfill $backfill, Collection $rows, string $key): array
    {
        $ids = $rows->pluck($key)->all();
        $before = $this->rawState($backfill, $key, $ids);

        try {
            $backfill->processBatch($rows);
        } catch (Throwable $e) {
            return collect($ids)
                ->map(fn ($id) => new SampleDiff((string) $id, [], $e->getMessage()))
                ->all();
        }

        $after = $this->rawState($backfill, $key, $ids);

        return collect($ids)->map(function ($id) use ($before, $after) {
            $key = (string) $id;

            return isset($after[$key])
                ? new SampleDiff($key, $this->diff($before[$key] ?? [], $after[$key]))
                : new SampleDiff($key, [], null, true);
        })->all();
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
