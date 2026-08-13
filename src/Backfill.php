<?php

namespace Kstmostofa\Backfill;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kstmostofa\Backfill\Models\BackfillRun;
use Throwable;

abstract class Backfill
{
    /**
     * Rows fetched per batch. -1 inherits config('backfill.batch_size').
     */
    public int $batchSize = -1;

    /**
     * Milliseconds to sleep between batches. -1 inherits config('backfill.sleep_ms').
     */
    public int $sleepMs = -1;

    /**
     * Wrap each batch in a transaction, committing the cursor alongside the work.
     *
     * Disabling this breaks the "cursor can never disagree with the data"
     * guarantee, so only turn it off when process() touches something the
     * database cannot roll back anyway.
     */
    public bool $useTransactions = true;

    /**
     * Hydrate Eloquent models for each row and call process().
     *
     * When false, rows arrive as stdClass from the query builder and
     * processBatch() is called once per batch instead.
     */
    public bool $hydrateModels = true;

    /**
     * Suppress model events (observers, activity log) for the duration of the run.
     */
    public bool $withoutModelEvents = false;

    /**
     * The set of rows to process.
     *
     * Prefer a self-excluding query — one that stops matching a row once that
     * row has been processed (e.g. whereNull('slug')). A self-excluding
     * collection is idempotent for free: re-running can never double-apply.
     */
    abstract public function collection(): Builder;

    /**
     * Process a single row. Called when $hydrateModels is true.
     *
     * @param  mixed  $record
     */
    public function process($record): void
    {
        //
    }

    /**
     * Process a whole batch at once. Called when $hydrateModels is false.
     */
    public function processBatch(Collection $rows): void
    {
        //
    }

    /**
     * Refuse to start when this returns false.
     */
    public function guard(): bool
    {
        return true;
    }

    public function beforeRun(BackfillRun $run): void
    {
        //
    }

    public function beforeBatch(Collection $rows, BackfillRun $run): void
    {
        //
    }

    public function afterBatch(Collection $rows, BackfillRun $run): void
    {
        //
    }

    public function afterRun(BackfillRun $run): void
    {
        //
    }

    /**
     * Called for every row whose process() threw. The exception has already
     * been recorded; this hook is for your own reporting.
     *
     * @param  mixed  $record
     */
    public function onRowFailed($record, Throwable $e): void
    {
        //
    }

    /**
     * The column paginated over. Must be unique and sortable — the whole
     * keyset strategy rests on it.
     */
    public function keyName(): string
    {
        return $this->collection()->getModel()->getKeyName();
    }

    /**
     * The identifier used on the command line: BackfillUserSlugs => user-slugs.
     */
    public function name(): string
    {
        $class = $this->namedClass();

        if ($class === null) {
            // An anonymous class with no meaningful parent still needs a
            // stable name, or the run lock has nothing to key on.
            return 'anonymous-'.substr(sha1(static::class), 0, 8);
        }

        $base = class_basename($class);

        if (Str::startsWith($base, 'Backfill')) {
            $base = Str::after($base, 'Backfill');
        } elseif (Str::endsWith($base, 'Backfill')) {
            $base = Str::beforeLast($base, 'Backfill');
        }

        return Str::kebab($base ?: class_basename($class));
    }

    /**
     * The class a name should be derived from. An anonymous subclass — a
     * one-off tweak of a real backfill — answers to the same name as its
     * parent, so it resumes and locks against the same run.
     *
     * @return class-string|null
     */
    protected function namedClass(): ?string
    {
        if (! str_contains(static::class, '@anonymous')) {
            return static::class;
        }

        $parent = get_parent_class($this);

        return $parent === false || $parent === self::class ? null : $parent;
    }

    /**
     * Human-readable description shown by backfill:list.
     */
    public function description(): string
    {
        return '';
    }

    public function resolvedBatchSize(): int
    {
        return $this->batchSize > 0
            ? $this->batchSize
            : (int) config('backfill.batch_size', 1000);
    }

    public function resolvedSleepMs(): int
    {
        return $this->sleepMs >= 0
            ? $this->sleepMs
            : (int) config('backfill.sleep_ms', 0);
    }
}
