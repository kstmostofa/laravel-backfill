<?php

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Kstmostofa\Backfill\Exceptions\BackfillRefused;
use Kstmostofa\Backfill\Support\MigrationGuard;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

afterEach(fn () => MigrationGuard::reset());

it('refuses to run inside a migration', function () {
    User::seedUnslugged(4);

    Event::dispatch(new MigrationsStarted('up'));

    expect(fn () => runBackfill(BackfillUserSlugs::class))
        ->toThrow(BackfillRefused::class, 'cannot run inside a migration');

    expect(User::whereNull('slug')->count())->toBe(4);
});

it('runs normally once the migration has finished', function () {
    User::seedUnslugged(4);

    Event::dispatch(new MigrationsStarted('up'));
    Event::dispatch(new MigrationsEnded('up'));

    $run = runBackfill(BackfillUserSlugs::class);

    expect($run->processed_count)->toBe(4);
});

it('refuses when guard() returns false', function () {
    User::seedUnslugged(4);

    $backfill = new class extends BackfillUserSlugs
    {
        public function guard(): bool
        {
            return false;
        }
    };

    expect(fn () => runBackfill($backfill))
        ->toThrow(BackfillRefused::class, 'guard() returned false');

    expect(User::whereNull('slug')->count())->toBe(4);
});
