<?php

use Kstmostofa\Backfill\Enums\RunStatus;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Pulse\BackfillsCard;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function () {
    if (! class_exists(\Laravel\Pulse\Livewire\Card::class)) {
        $this->markTestSkipped('Laravel Pulse is not installed.');
    }

    // The card is #[Lazy], like Pulse's own, so its first render is a
    // placeholder. Tests want the real thing.
    Livewire::withoutLazyLoading();
});

/**
 * Build a run in a given state without having to drive a real one there.
 */
function runInState(string $backfill, RunStatus $status, array $attributes = []): BackfillRun
{
    return BackfillRun::create(array_merge([
        'backfill' => $backfill,
        'backfill_class' => BackfillUserSlugs::class,
        'status' => $status,
        'key_name' => 'id',
        'batch_size' => 100,
        'sleep_ms' => 0,
        'dry_run' => false,
        'heartbeat_at' => now(),
    ], $attributes));
}

it('shows runs that are in flight or want attention', function () {
    runInState('a-running', RunStatus::Running);
    runInState('b-paused', RunStatus::Paused);
    runInState('c-failed', RunStatus::Failed);

    Livewire::test(BackfillsCard::class)
        ->assertSee('a-running')
        ->assertSee('b-paused')
        ->assertSee('c-failed');
});

it('leaves finished runs off the dashboard', function () {
    User::seedUnslugged(4);
    runBackfill(BackfillUserSlugs::class);

    runInState('still-going', RunStatus::Running);

    Livewire::test(BackfillsCard::class)
        ->assertSee('still-going')
        ->assertDontSee('user-slugs');
});

it('puts problems above healthy work', function () {
    runInState('a-running', RunStatus::Running);
    runInState('z-failed', RunStatus::Failed);

    // Alphabetically 'z-failed' would come last; it should still be first,
    // because a failed run is the thing you opened Pulse to find.
    Livewire::test(BackfillsCard::class)
        ->assertSeeInOrder(['z-failed', 'a-running']);
});

it('says nothing when there is nothing to say', function () {
    Livewire::test(BackfillsCard::class)->assertDontSee('a-running');
});

it('shows why a run stopped', function () {
    runInState('halted', RunStatus::Paused, [
        'meta' => ['stop_reason' => 'Circuit breaker tripped: too many failures.'],
    ]);

    Livewire::test(BackfillsCard::class)->assertSee('Circuit breaker tripped');
});

it('flags a run whose heartbeat has gone cold', function () {
    runInState('crashed', RunStatus::Running, ['heartbeat_at' => now()->subHour()]);

    Livewire::test(BackfillsCard::class)->assertSee('stale');
});

it('identifies which tenant a run belongs to', function () {
    runInState('per-tenant', RunStatus::Running, ['tenant' => 'acme']);

    Livewire::test(BackfillsCard::class)->assertSee('acme');
});

it('reports progress as a percentage when the scope is known', function () {
    runInState('measured', RunStatus::Running, [
        'total_estimate' => 200,
        'processed_count' => 50,
    ]);

    Livewire::test(BackfillsCard::class)->assertSee('25%');
});

it('falls back to a raw count with no estimate', function () {
    runInState('unmeasured', RunStatus::Running, ['processed_count' => 1234]);

    Livewire::test(BackfillsCard::class)->assertSee('1,234');
});
