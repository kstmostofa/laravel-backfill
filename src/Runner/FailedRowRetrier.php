<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Throwable;

/**
 * Re-processes only the rows a run recorded as failed.
 *
 * The usual sequence is: a run finishes with a few hundred failures, you read
 * the errors, fix the cause, and want those rows and nothing else. Re-running
 * the whole backfill would work for a self-excluding collection, but it means
 * walking eight million rows to reach the two hundred that matter.
 */
class FailedRowRetrier
{
    public function __construct(protected LockManager $locks) {}

    /**
     * @return array{retried: int, resolved: int, failed: int}
     */
    public function retry(Backfill $backfill, BackfillRun $run, ?int $limit = null): array
    {
        $this->locks->acquire($backfill->name(), $run->id);

        try {
            return $this->process($backfill, $run, $limit);
        } finally {
            $this->locks->release($backfill->name());
        }
    }

    /**
     * @return array{retried: int, resolved: int, failed: int}
     */
    protected function process(Backfill $backfill, BackfillRun $run, ?int $limit): array
    {
        $query = BackfillRunError::query()
            ->where('run_id', $run->id)
            ->whereNull('resolved_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $errors = $query->get();

        if ($errors->isEmpty()) {
            return ['retried' => 0, 'resolved' => 0, 'failed' => 0];
        }

        $model = $backfill->collection()->getModel();
        $key = $run->key_name;

        $records = $model->newQueryWithoutScopes()
            ->whereIn($key, $errors->pluck('record_id')->filter()->all())
            ->get()
            ->keyBy(fn ($record) => (string) $record->{$key});

        $resolved = 0;
        $failed = 0;

        foreach ($errors as $error) {
            $record = $records->get((string) $error->record_id);

            if ($record === null) {
                // The row is gone. Nothing left to retry, so stop counting it
                // against the run.
                $error->forceFill(['resolved_at' => now()])->save();
                $resolved++;

                continue;
            }

            try {
                DB::connection(config('backfill.connection'))->transaction(
                    fn () => $backfill->process($record)
                );

                $error->forceFill(['resolved_at' => now()])->save();
                $resolved++;
            } catch (Throwable $e) {
                $error->forceFill([
                    'attempts' => $error->attempts + 1,
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ])->save();

                $backfill->onRowFailed($record, $e);
                $failed++;
            }
        }

        if ($resolved > 0) {
            $run->forceFill([
                'processed_count' => $run->processed_count + $resolved,
                'failed_count' => max(0, $run->failed_count - $resolved),
            ])->save();
        }

        return ['retried' => $errors->count(), 'resolved' => $resolved, 'failed' => $failed];
    }
}
