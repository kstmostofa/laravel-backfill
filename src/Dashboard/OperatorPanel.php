<?php

namespace Kstmostofa\Backfill\Dashboard;

use Illuminate\Support\Collection;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Exceptions\BackfillNotFound;
use Kstmostofa\Backfill\Jobs\RunBackfillJob;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Parameters\ParameterBag;
use Livewire\Component;
use Throwable;

/**
 * The panel support staff use.
 *
 * Only backfills a developer has marked $operatorRunnable appear here, only
 * their declared parameters can be set, and every input is validated before a
 * job is queued. Someone can paste a list of order ids and watch a progress
 * bar; they cannot reach anything that was not deliberately exposed.
 */
class OperatorPanel extends Component
{
    public ?string $selected = null;

    /** @var array<string, mixed> */
    public array $input = [];

    /** @var array<int, string> */
    public array $errors = [];

    public ?string $flash = null;

    public function select(?string $name): void
    {
        $this->selected = $name;
        $this->input = [];
        $this->errors = [];
        $this->flash = null;

        if ($name === null) {
            return;
        }

        try {
            foreach ($this->registry()->find($name)->parameters() as $parameter) {
                $this->input[$parameter->key] = $parameter->default;
            }
        } catch (BackfillNotFound) {
            $this->selected = null;
        }
    }

    public function run(): void
    {
        $this->errors = [];
        $this->flash = null;

        if ($this->selected === null) {
            return;
        }

        try {
            $backfill = $this->registry()->find($this->selected);
        } catch (BackfillNotFound $e) {
            $this->errors = [$e->getMessage()];

            return;
        }

        if (! $backfill->operatorRunnable) {
            $this->errors = ['That backfill is not available here.'];

            return;
        }

        $result = ParameterBag::validate($backfill, $this->input);

        if ($result['errors'] !== []) {
            $this->errors = $result['errors'];

            return;
        }

        try {
            RunBackfillJob::dispatch(
                $backfill->name(),
                startedBy: $this->startedBy(),
                parameters: $result['values'],
            )
                ->onConnection(config('backfill.queue.connection'))
                ->onQueue(config('backfill.queue.queue'));
        } catch (Throwable $e) {
            $this->errors = [$e->getMessage()];

            return;
        }

        $this->flash = sprintf(
            'Started %s. It runs in the background — this page updates as it goes.',
            $backfill->description() ?: $backfill->name(),
        );
    }

    /**
     * @return Collection<int, Backfill>
     */
    public function getAvailableProperty(): Collection
    {
        return $this->registry()->all()->filter(fn (Backfill $backfill) => $backfill->operatorRunnable)->values();
    }

    public function getBackfillProperty(): ?Backfill
    {
        if ($this->selected === null) {
            return null;
        }

        try {
            return $this->registry()->find($this->selected);
        } catch (BackfillNotFound) {
            return null;
        }
    }

    public function getRunProperty(): ?BackfillRun
    {
        return $this->selected
            ? BackfillRun::query()->where('backfill', $this->selected)->latest('id')->first()
            : null;
    }

    /**
     * Plain words for someone who does not know what a cursor is.
     */
    public function getProgressLabelProperty(): ?string
    {
        $run = $this->run;

        if (! $run) {
            return null;
        }

        return match ($run->status) {
            RunStatus::Running => sprintf('Working — %s done so far.', number_format($run->processed_count)),
            RunStatus::Completed => sprintf('Finished. %s processed.', number_format($run->processed_count)),
            RunStatus::Failed => 'Stopped with a problem. Someone technical needs to look.',
            RunStatus::Paused, RunStatus::Interrupted => sprintf('Paused after %s.', number_format($run->processed_count)),
            RunStatus::Cancelled => 'Cancelled.',
            RunStatus::Pending => 'Queued, starting shortly.',
        };
    }

    protected function registry(): BackfillRegistry
    {
        return app(BackfillRegistry::class);
    }

    protected function startedBy(): string
    {
        $user = auth()->user();

        return $user ? 'operator:'.($user->email ?? $user->getAuthIdentifier()) : 'operator';
    }

    public function render()
    {
        return view('backfill::operator');
    }
}
