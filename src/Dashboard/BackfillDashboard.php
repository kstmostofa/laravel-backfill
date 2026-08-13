<?php

namespace Kstmostofa\Backfill\Dashboard;

use Illuminate\Support\Collection;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Models\BackfillRunBatch;
use Kstmostofa\Backfill\Models\BackfillRunError;
use Kstmostofa\Backfill\Runner\FailedRowRetrier;
use Livewire\Component;
use Throwable;

class BackfillDashboard extends Component
{
    public ?string $selected = null;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(?string $selected = null): void
    {
        $this->selected = $selected;
    }

    public function select(?string $name): void
    {
        $this->selected = $name;
        $this->flash = null;
        $this->error = null;
    }

    /**
     * Runs are started on the queue rather than in the web request. A backfill
     * takes hours; an HTTP worker does not.
     */
    public function start(string $name): void
    {
        $this->guarded($name, function (Backfill $backfill) {
            RunBackfillJob::dispatch($backfill->name(), startedBy: $this->startedBy())
                ->onConnection(config('backfill.queue.connection'))
                ->onQueue(config('backfill.queue.queue'));

            $this->flash = "Queued [{$backfill->name()}].";
        });
    }

    public function pause(string $name): void
    {
        $this->guarded($name, function (Backfill $backfill) {
            $run = $this->latestRun($backfill->name());

            if (! $run || ! in_array($run->status, [RunStatus::Running, RunStatus::Pending], true)) {
                $this->error = "[{$backfill->name()}] is not running.";

                return;
            }

            $run->forceFill(['status' => RunStatus::Paused])->save();
            $this->flash = "[{$backfill->name()}] will stop after the batch in flight commits.";
        });
    }

    public function resume(string $name): void
    {
        $this->guarded($name, function (Backfill $backfill) {
            RunBackfillJob::dispatch($backfill->name(), startedBy: $this->startedBy())
                ->onConnection(config('backfill.queue.connection'))
                ->onQueue(config('backfill.queue.queue'));

            $this->flash = "Resuming [{$backfill->name()}] from its committed cursor.";
        });
    }

    public function cancel(string $name): void
    {
        $this->guarded($name, function (Backfill $backfill) {
            $run = $this->latestRun($backfill->name());

            if (! $run || $run->status->isTerminal()) {
                $this->error = "[{$backfill->name()}] has nothing to cancel.";

                return;
            }

            $run->forceFill(['status' => RunStatus::Cancelled, 'finished_at' => now()])->save();
            $this->flash = "[{$backfill->name()}] cancelled.";
        });
    }

    public function retryFailed(string $name): void
    {
        $this->guarded($name, function (Backfill $backfill) {
            $run = $this->latestRun($backfill->name());

            if (! $run || $run->failed_count === 0) {
                $this->error = 'Nothing to retry.';

                return;
            }

            $result = app(FailedRowRetrier::class)->retry($backfill, $run);

            $this->flash = sprintf(
                'Retried %d rows: %d succeeded, %d still failing.',
                $result['retried'],
                $result['resolved'],
                $result['failed'],
            );
        });
    }

    protected function guarded(string $name, callable $callback): void
    {
        $this->flash = null;
        $this->error = null;

        try {
            $callback(app(BackfillRegistry::class)->find($name));
        } catch (BackfillNotFound $e) {
            $this->error = $e->getMessage();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function startedBy(): string
    {
        $user = auth()->user();

        return $user ? 'dashboard:'.($user->email ?? $user->getAuthIdentifier()) : 'dashboard';
    }

    protected function latestRun(string $name): ?BackfillRun
    {
        return BackfillRun::query()->where('backfill', $name)->latest('id')->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getRowsProperty(): Collection
    {
        $backfills = app(BackfillRegistry::class)->all();

        $latest = BackfillRun::query()
            ->whereIn('backfill', $backfills->map->name()->all())
            ->orderByDesc('id')
            ->get()
            ->groupBy('backfill')
            ->map->first();

        return $backfills->map(function (Backfill $backfill) use ($latest) {
            $run = $latest->get($backfill->name());

            return [
                'name' => $backfill->name(),
                'class' => class_basename($backfill),
                'description' => $backfill->description(),
                'run' => $run,
                'stale' => $run?->isStale() ?? false,
            ];
        });
    }

    public function getRunProperty(): ?BackfillRun
    {
        return $this->selected ? $this->latestRun($this->selected) : null;
    }

    /**
     * @return Collection<int, BackfillRunError>
     */
    public function getErrorsProperty(): Collection
    {
        $run = $this->run;

        return $run
            ? $run->errors()->whereNull('resolved_at')->latest('id')->limit(25)->get()
            : collect();
    }

    /**
     * Recent batch durations, scaled to a 0–100 height for the sparkline.
     *
     * @return array<int, array{height: float, ms: int}>
     */
    public function getSparklineProperty(): array
    {
        $run = $this->run;

        if (! $run) {
            return [];
        }

        $durations = BackfillRunBatch::query()
            ->where('run_id', $run->id)
            ->latest('id')
            ->limit(40)
            ->pluck('duration_ms')
            ->reverse()
            ->values();

        if ($durations->isEmpty()) {
            return [];
        }

        $max = max(1, $durations->max());

        return $durations
            ->map(fn (int $ms) => ['height' => round($ms / $max * 100, 1), 'ms' => $ms])
            ->all();
    }

    public function render()
    {
        return view('backfill::dashboard');
    }
}
