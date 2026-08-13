<?php

namespace Kstmostofa\Backfill\Runner;

use Illuminate\Support\Carbon;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;

/**
 * The two questions worth asking before a data change starts: is this bigger
 * than whoever typed the command expects, and is now a sane time to do it.
 */
class ProductionGuards
{
    public function check(Backfill $backfill, ?int $estimate, bool $force): void
    {
        if ($window = $this->activeFreezeWindow()) {
            if (! $force) {
                throw BackfillRefused::duringFreeze($backfill->name(), $window);
            }
        }

        if ($force || $estimate === null) {
            return;
        }

        $ceiling = config('backfill.guards.max_rows_without_confirmation');

        if ($ceiling !== null && $estimate > (int) $ceiling) {
            throw BackfillRefused::tooManyRows($backfill->name(), $estimate, (int) $ceiling);
        }
    }

    /**
     * Does the row count need looking up at all? Skipping the COUNT matters on
     * the tables this package exists for.
     */
    public function needsEstimate(bool $force): bool
    {
        return ! $force && config('backfill.guards.max_rows_without_confirmation') !== null;
    }

    /**
     * A human-readable description of the freeze window we are currently
     * inside, or null when we are not.
     */
    public function activeFreezeWindow(): ?string
    {
        $config = config('backfill.guards.deploy_freeze', []);

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $timezone = $config['timezone'] ?: config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);
        $today = strtolower($now->format('D'));
        $minutes = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        foreach ($config['windows'] ?? [] as $window) {
            $days = array_map('strtolower', $window['days'] ?? []);

            if ($days !== [] && ! in_array($today, $days, true)) {
                continue;
            }

            $from = $this->minutesOf($window['from'] ?? '00:00');
            $to = $this->minutesOf($window['to'] ?? '23:59');

            $inside = $from <= $to
                ? ($minutes >= $from && $minutes <= $to)
                // A window such as 22:00–02:00 wraps past midnight.
                : ($minutes >= $from || $minutes <= $to);

            if ($inside) {
                return sprintf(
                    '%s %s–%s (%s)',
                    $days === [] ? 'daily' : implode('/', $days),
                    $window['from'] ?? '00:00',
                    $window['to'] ?? '23:59',
                    $timezone,
                );
            }
        }

        return null;
    }

    protected function minutesOf(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hours) * 60 + (int) $minutes;
    }
}
