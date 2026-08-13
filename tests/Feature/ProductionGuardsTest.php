<?php

use Illuminate\Support\Carbon;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Models\BackfillRun;
use Kstmostofa\Backfill\Runner\ProductionGuards;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

afterEach(fn () => Carbon::setTestNow());

it('refuses a run bigger than the configured ceiling', function () {
    config()->set('backfill.guards.max_rows_without_confirmation', 5);
    User::seedUnslugged(10);

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillRefused::class, 'matches 10 rows, above the 5 row ceiling');

    expect(User::whereNull('slug')->count())->toBe(10)
        ->and(BackfillRun::count())->toBe(0);
});

it('allows a run at the ceiling', function () {
    config()->set('backfill.guards.max_rows_without_confirmation', 10);
    User::seedUnslugged(10);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(10);
});

it('lets --force through the ceiling', function () {
    config()->set('backfill.guards.max_rows_without_confirmation', 5);
    User::seedUnslugged(10);

    expect(runBackfill(BackfillUserSlugs::class, ['force' => true])->processed_count)->toBe(10);
});

it('does not count rows when there is no ceiling to check', function () {
    config()->set('backfill.guards.max_rows_without_confirmation', null);
    User::seedUnslugged(4);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(4);
});

it('refuses to start inside a deploy freeze window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-14 16:30:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['days' => ['fri'], 'from' => '15:00', 'to' => '23:59']],
    ]);

    User::seedUnslugged(4);

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillRefused::class, 'deploy freeze window');

    expect(User::whereNull('slug')->count())->toBe(4);
});

it('runs outside the freeze window', function () {
    // Same Friday, but before the window opens.
    Carbon::setTestNow(Carbon::parse('2026-08-14 09:00:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['days' => ['fri'], 'from' => '15:00', 'to' => '23:59']],
    ]);

    User::seedUnslugged(4);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(4);
});

it('ignores a window on a different day', function () {
    // 2026-08-14 is a Friday; the window is for Sundays.
    Carbon::setTestNow(Carbon::parse('2026-08-14 16:30:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['days' => ['sun'], 'from' => '15:00', 'to' => '23:59']],
    ]);

    User::seedUnslugged(4);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(4);
});

it('handles a window that wraps past midnight', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-14 01:00:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['from' => '22:00', 'to' => '02:00']],
    ]);

    expect(app(ProductionGuards::class)->activeFreezeWindow())->toContain('22:00–02:00');
});

it('treats a window with no days as daily', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['from' => '09:00', 'to' => '17:00']],
    ]);

    expect(app(ProductionGuards::class)->activeFreezeWindow())->toStartWith('daily');
});

it('lets --force through a freeze window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-14 16:30:00', 'UTC'));

    config()->set('backfill.guards.deploy_freeze', [
        'enabled' => true,
        'timezone' => 'UTC',
        'windows' => [['days' => ['fri'], 'from' => '15:00', 'to' => '23:59']],
    ]);

    User::seedUnslugged(4);

    expect(runBackfill(BackfillUserSlugs::class, ['force' => true])->processed_count)->toBe(4);
});

it('does nothing when freezes are disabled', function () {
    config()->set('backfill.guards.deploy_freeze.enabled', false);

    expect(app(ProductionGuards::class)->activeFreezeWindow())->toBeNull();
});
