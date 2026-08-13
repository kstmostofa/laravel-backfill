<?php

namespace Kstmostofa\Backfill\DryRun;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Throwable;

/**
 * Stops a dry run from touching the outside world, and reports what it would
 * have touched instead.
 *
 * Database writes are handled separately, by rolling the transaction back. Mail
 * and HTTP calls have no rollback, so they have to be intercepted before they
 * happen — a "dry" run that emails four million customers is the single worst
 * thing this package could do.
 */
class SideEffectRecorder
{
    /** The mailer name swapped in for the duration of the dry run. */
    public const MAILER = 'backfill_dry_run';

    /** @var array<string, int> */
    protected array $events = [];

    protected bool $installed = false;

    protected ?string $previousMailer = null;

    public function install(): void
    {
        $this->interceptMail();

        Notification::fake();
        Bus::fake();
        Queue::fake();

        Http::preventStrayRequests();
        Http::fake();

        // Events are recorded rather than faked. Faking them would stop model
        // observers running, and the before/after diff would then show
        // something a real run would never produce.
        Event::listen('*', function (string $name) {
            if ($this->isFrameworkNoise($name)) {
                return;
            }

            $this->events[$name] = ($this->events[$name] ?? 0) + 1;
        });

        $this->installed = true;
    }

    /**
     * Mail::fake() looks like the obvious choice here, but its raw() method is
     * a no-op that records nothing — a dry run would silently lose every
     * Mail::raw() call and report no mail at all. Swapping in the array
     * transport sends nothing and captures everything, raw included.
     */
    protected function interceptMail(): void
    {
        $this->previousMailer = config('mail.default');

        config([
            'mail.mailers.'.self::MAILER => ['transport' => 'array'],
            'mail.default' => self::MAILER,
        ]);

        Mail::forgetMailers();
    }

    public function restore(): void
    {
        if ($this->previousMailer !== null) {
            config(['mail.default' => $this->previousMailer]);
            Mail::forgetMailers();
        }
    }

    /**
     * @return array<string, int|null>
     */
    public function collect(): array
    {
        if (! $this->installed) {
            return [];
        }

        return array_filter([
            'mail' => $this->countMail(),
            'notifications' => $this->countNotifications(),
            'queued jobs' => $this->countQueuedJobs(),
            'dispatched jobs' => $this->countProtected(Bus::getFacadeRoot(), ['commands', 'commandsSync', 'commandsAfterResponse']),
            'http requests' => $this->countHttp(),
        ], fn ($count) => $count === null || $count > 0);
    }

    /**
     * @return array<string, int>
     */
    public function events(): array
    {
        return $this->events;
    }

    protected function countMail(): int
    {
        try {
            return Mail::mailer(self::MAILER)->getSymfonyTransport()->messages()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function countNotifications(): int
    {
        try {
            return collect(Notification::sentNotifications())->flatten(2)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function countQueuedJobs(): int
    {
        try {
            return collect(Queue::pushedJobs())->map(fn ($pushes) => count($pushes))->sum();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function countHttp(): int
    {
        try {
            return Http::recorded()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Bus keeps its collections protected with no public accessor. Reading them
     * is a deliberate, contained risk: if a future Laravel moves them, the
     * count degrades to null and the report says the number is unavailable
     * rather than the dry run breaking.
     *
     * @param  array<int, string>  $properties
     */
    protected function countProtected(object $fake, array $properties): ?int
    {
        $total = 0;
        $read = false;

        foreach ($properties as $property) {
            try {
                if (! property_exists($fake, $property)) {
                    continue;
                }

                $reflection = new ReflectionProperty($fake, $property);
                $reflection->setAccessible(true);

                $value = $reflection->getValue($fake);

                if (is_array($value)) {
                    $total += $this->countLeaves($value);
                    $read = true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $read ? $total : null;
    }

    /**
     * These collections are sometimes flat and sometimes keyed by class with
     * arrays underneath, so count the leaves rather than assuming a shape.
     */
    protected function countLeaves(array $value): int
    {
        $count = 0;

        foreach ($value as $item) {
            $count += is_array($item) ? $this->countLeaves($item) : 1;
        }

        return $count;
    }

    protected function isFrameworkNoise(string $name): bool
    {
        return str_starts_with($name, 'eloquent.')
            || str_starts_with($name, 'Illuminate\\')
            || str_starts_with($name, 'composer')
            || str_starts_with($name, 'bootstrapp')
            || str_starts_with($name, 'creating:')
            || str_starts_with($name, 'composing:');
    }
}
