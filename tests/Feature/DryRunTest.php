<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Kstmostofa\Backfill\Backfill;
use Kstmostofa\Backfill\DryRun\DryRunner;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithFailingRow;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillWithoutHydration;
use Kstmostofa\Backfill\Tests\Fixtures\User;

function dryRun($backfill, ?int $samples = null)
{
    $instance = is_string($backfill) ? new $backfill : $backfill;

    return app(DryRunner::class)->perform($instance, $samples);
}

beforeEach(fn () => BackfillWithFailingRow::reset());

it('writes absolutely nothing', function () {
    User::seedUnslugged(10);

    dryRun(BackfillUserSlugs::class);

    expect(User::whereNull('slug')->count())->toBe(10)
        ->and(User::where('process_count', 0)->count())->toBe(10);
});

it('does not leave a run record behind', function () {
    User::seedUnslugged(5);

    dryRun(BackfillUserSlugs::class);

    expect(BackfillRun::count())->toBe(0);
});

it('reports how many rows the backfill would touch', function () {
    User::seedUnslugged(10);

    $report = dryRun(BackfillUserSlugs::class);

    expect($report->scope)->toBe(10)
        ->and($report->backfill)->toBe('user-slugs');
});

it('shows the real before and after for each sampled row', function () {
    User::seedUnslugged(10);

    $report = dryRun(BackfillUserSlugs::class, 3);

    expect($report->samples)->toHaveCount(3);

    $first = $report->samples[0];

    expect($first->changes)->toHaveKey('slug')
        ->and($first->changes['slug']['from'])->toBeNull()
        ->and($first->changes['slug']['to'])->toBe('user-1')
        ->and($first->changes)->toHaveKey('process_count')
        // Plain ASCII: Laravel's console output silently strips U+2192, so a
        // unicode arrow here would render as "slug: null user-1".
        ->and($first->summary())->toContain('slug: null -> user-1');
});

it('renders both sides of a diff in the same format', function () {
    User::seedUnslugged(3);

    $report = dryRun(BackfillUserSlugs::class, 1);

    // getOriginal() would give a Carbon for updated_at while the "after" side
    // reads a raw string, so a merely-touched timestamp would print as
    // '"2026-08-14T14:51:13.000000Z" -> 2026-08-14 14:51:40'.
    $summary = $report->samples[0]->summary();

    expect($summary)->not->toContain('"')
        ->and($summary)->not->toContain('T00:00:00');
});

it('keeps the diff free of characters the console strips', function () {
    User::seedUnslugged(2);

    expect(dryRun(BackfillUserSlugs::class, 1)->samples[0]->summary())
        ->not->toContain('→');
});

it('samples only as many rows as asked for', function () {
    User::seedUnslugged(50);

    expect(dryRun(BackfillUserSlugs::class, 2)->samples)->toHaveCount(2);
});

it('estimates how long the whole thing would take', function () {
    User::seedUnslugged(100);

    $report = dryRun(BackfillUserSlugs::class, 5);

    expect($report->estimatedSeconds())->toBeGreaterThan(0)
        ->and($report->estimatedDuration())->toBeString();
});

it('produces a readable query plan', function () {
    User::seedUnslugged(5);

    $report = dryRun(BackfillUserSlugs::class);

    // Whether a five-row table reports "indexed" is up to the planner —
    // PostgreSQL sensibly sorts tiny tables no matter what indexes exist — so
    // the parsing itself is pinned down by the unit tests below.
    expect($report->plan->detail)->not->toBeEmpty()
        ->and($report->plan->label())->toBeIn(['indexed', 'NOT INDEXED', 'unknown']);
});

it('warns when a row would fail rather than pretending it would not', function () {
    User::seedUnslugged(5);
    BackfillWithFailingRow::$poisoned = [2];

    $report = dryRun(BackfillWithFailingRow::class, 3);

    expect($report->failingSamples())->toBe(1)
        ->and($report->samples[1]->error)->toContain('Row 2 is poisoned')
        ->and($report->samples[1]->summary())->toStartWith('would fail:');
});

it('notices when the work would change nothing', function () {
    User::seedUnslugged(5);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            // Reads, decides, does nothing — the bug a dry run should surface.
        }
    };

    $report = dryRun($backfill);

    expect($report->wouldChangeNothing())->toBeTrue()
        ->and($report->samples[0]->summary())->toBe('no change');
});

it('handles a backfill with nothing to do', function () {
    $report = dryRun(BackfillUserSlugs::class);

    expect($report->scope)->toBe(0)
        ->and($report->samples)->toBe([])
        ->and($report->estimatedSeconds())->toBeNull();
});

it('works on the un-hydrated fast path too', function () {
    User::seedUnslugged(10);

    $report = dryRun(BackfillWithoutHydration::class, 4);

    expect($report->samples)->toHaveCount(4)
        ->and($report->samples[0]->changes)->toHaveKey('slug')
        ->and(User::whereNull('slug')->count())->toBe(10);
});

it('reports a row that would be deleted', function () {
    User::seedUnslugged(3);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            $record->delete();
        }
    };

    $report = dryRun($backfill, 1);

    expect($report->samples[0]->deleted)->toBeTrue()
        ->and($report->samples[0]->summary())->toBe('row would be deleted')
        ->and(User::count())->toBe(3);
});

it('intercepts mail instead of sending it', function () {
    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            Mail::raw('Your account changed', fn ($message) => $message->to('someone@example.com'));

            parent::process($record);
        }
    };

    $report = dryRun($backfill, 2);

    // Two rows sampled, two mails caught. Mail::raw() is the case that matters
    // here: Mail::fake() silently discards it, so the dry run would have
    // reported no mail at all.
    expect($report->sideEffects)->toHaveKey('mail')
        ->and($report->sideEffects['mail'])->toBe(2);
});

it('restores the mailer after the dry run', function () {
    User::seedUnslugged(2);

    $before = config('mail.default');

    dryRun(BackfillUserSlugs::class);

    expect(config('mail.default'))->toBe($before);
});

it('intercepts outbound http calls', function () {
    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            Http::post('https://example.com/webhook', ['id' => $record->id]);

            parent::process($record);
        }
    };

    $report = dryRun($backfill, 3);

    expect($report->sideEffects)->toHaveKey('http requests')
        ->and($report->sideEffects['http requests'])->toBe(3);
});

it('reports no side effects when there are none', function () {
    User::seedUnslugged(4);

    expect(dryRun(BackfillUserSlugs::class)->hasSideEffects())->toBeFalse();
});

it('records application events without suppressing them', function () {
    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public function process($record): void
        {
            event('app.thing.happened');

            parent::process($record);
        }
    };

    $report = dryRun($backfill, 2);

    expect($report->events)->toHaveKey('app.thing.happened')
        ->and($report->events['app.thing.happened'])->toBe(2);
});

it('refuses to dry-run a backfill whose guard says no', function () {
    $backfill = new class extends BackfillUserSlugs
    {
        public function guard(): bool
        {
            return false;
        }
    };

    expect(fn () => dryRun($backfill))
        ->toThrow(\Kstmostofa\Backfill\Exceptions\BackfillRefused::class);
});

it('flags a cursor column with no index', function () {
    // bf_tags.name has no index, so paginating over it is a full scan.
    \Kstmostofa\Backfill\Tests\Fixtures\Tag::seed(5);

    $backfill = new class extends Backfill
    {
        public function collection(): Builder
        {
            return \Kstmostofa\Backfill\Tests\Fixtures\Tag::query()->whereNull('label');
        }

        public function keyName(): string
        {
            return 'name';
        }

        public function process($record): void
        {
            $record->forceFill(['label' => $record->name])->save();
        }
    };

    expect(dryRun($backfill)->plan->usesIndex)->toBeFalse();
});
