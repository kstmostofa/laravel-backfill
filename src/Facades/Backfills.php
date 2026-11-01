<?php

namespace Kstmostofa\Backfill\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Testing\BackfillRunnerFake;

/**
 * The backfill runner. Plural on purpose: `Backfill` is already the base class
 * every backfill extends.
 *
 * @method static \Kstmostofa\Backfill\Models\BackfillRun run(\Kstmostofa\Backfill\Backfill $backfill, ?\Kstmostofa\Backfill\Runner\RunOptions $options = null)
 * @method static array<int, \Kstmostofa\Backfill\Models\BackfillRun> runAll(\Kstmostofa\Backfill\Backfill $backfill, ?\Kstmostofa\Backfill\Runner\RunOptions $options = null)
 * @method static \Kstmostofa\Backfill\Models\BackfillRun|null resumableRun(\Kstmostofa\Backfill\Backfill $backfill, ?string $tenant = null)
 * @method static void assertRan(string $backfill, ?Closure $callback = null)
 * @method static void assertRanTimes(string $backfill, int $times)
 * @method static void assertNotRan(string $backfill, ?Closure $callback = null)
 * @method static void assertNothingRan()
 *
 * @see BackfillRunner
 * @see BackfillRunnerFake
 */
class Backfills extends Facade
{
    /**
     * Replace the runner with one that records runs and changes nothing.
     */
    public static function fake(): BackfillRunnerFake
    {
        $fake = static::getFacadeApplication()->make(BackfillRunnerFake::class);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return BackfillRunner::class;
    }
}
