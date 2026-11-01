<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Support\Collection;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\BackfillRegistry;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Parameters\Parameter;

/**
 * Works out which backfills still have work to do, the way `migrate` works out
 * which migrations have not run.
 *
 * A backfill is pending until its latest run completed or was cancelled. A
 * paused, failed or interrupted run counts as pending, because running it
 * again resumes from its cursor.
 *
 * Some backfills are never picked up: those marked $manual, those offered in
 * the operator panel, and those with a required parameter. Each of those needs
 * a person to decide when and with what inputs it runs.
 */
class PendingBackfills
{
    public function __construct(protected BackfillRegistry $registry) {}

    /**
     * @return Collection<int, Backfill>
     */
    public function all(): Collection
    {
        return $this->registry->all()
            ->filter(fn (Backfill $backfill) => $this->isAutomatic($backfill) && $this->isPending($backfill))
            ->values();
    }

    /**
     * Can this backfill run without a person choosing when, or with what?
     */
    public function isAutomatic(Backfill $backfill): bool
    {
        if ($backfill->manual || $backfill->operatorRunnable) {
            return false;
        }

        foreach ($backfill->parameters() as $parameter) {
            /** @var Parameter $parameter */
            if ($parameter->required && $parameter->default === null) {
                return false;
            }
        }

        return true;
    }

    public function isPending(Backfill $backfill): bool
    {
        $tenants = $backfill->tenants();

        if ($tenants === null) {
            return ! $this->finished($this->latestRun($backfill->name(), null));
        }

        foreach ($tenants as $tenant) {
            if (! $this->finished($this->latestRun($backfill->name(), (string) $tenant))) {
                return true;
            }
        }

        return false;
    }

    protected function latestRun(string $name, ?string $tenant): ?BackfillRun
    {
        return BackfillRun::query()
            ->where('backfill', $name)
            ->when($tenant === null,
                fn ($query) => $query->whereNull('tenant'),
                fn ($query) => $query->where('tenant', $tenant),
            )
            ->latest('id')
            ->first();
    }

    protected function finished(?BackfillRun $run): bool
    {
        return $run !== null && $run->status->isTerminal();
    }
}
