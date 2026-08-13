<?php

namespace Kstmostofa\Backfill\Pulse;

use Illuminate\Support\Collection;
use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * A Pulse card for backfills that are in flight or want attention.
 *
 * Deliberately not a period-scoped metric like the rest of Pulse: a backfill
 * that has been paused for three days is exactly what you want on the
 * dashboard, and a period filter would hide it.
 */
#[Lazy]
class BackfillsCard extends Card
{
    public function render()
    {
        return view('backfill::pulse.card', [
            'runs' => $this->runs(),
        ]);
    }

    /**
     * @return Collection<int, BackfillRun>
     */
    protected function runs(): Collection
    {
        return BackfillRun::query()
            ->whereIn('status', [
                RunStatus::Running->value,
                RunStatus::Paused->value,
                RunStatus::Interrupted->value,
                RunStatus::Failed->value,
                RunStatus::Pending->value,
            ])
            ->orderByRaw($this->statusOrdering())
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * Problems first, then work in progress. An operator opening Pulse should
     * see the failed run before the healthy one.
     */
    protected function statusOrdering(): string
    {
        return "case status
            when 'failed' then 0
            when 'interrupted' then 1
            when 'paused' then 2
            when 'running' then 3
            else 4
        end";
    }
}
