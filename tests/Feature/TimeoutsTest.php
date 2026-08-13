<?php

use Illuminate\Support\Facades\DB;
use Kstmostofa\Backfill\Runner\ConnectionTimeouts;
use Kstmostofa\Backfill\Tests\Fixtures\Backfills\BackfillUserSlugs;
use Kstmostofa\Backfill\Tests\Fixtures\User;

it('applies the configured timeouts without breaking the run', function () {
    config()->set('backfill.timeouts.statement', 30000);
    config()->set('backfill.timeouts.lock', 5000);

    User::seedUnslugged(6);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(6);
});

it('leaves the session alone when no timeouts are configured', function () {
    config()->set('backfill.timeouts.statement', null);
    config()->set('backfill.timeouts.lock', null);

    User::seedUnslugged(4);

    expect(runBackfill(BackfillUserSlugs::class)->processed_count)->toBe(4);
});

it('actually sets the timeout on PostgreSQL', function () {
    $connection = DB::connection();

    app(ConnectionTimeouts::class)->apply($connection, 12345, 6789);

    expect($connection->select('show statement_timeout')[0]->statement_timeout)->toBe('12345ms')
        ->and($connection->select('show lock_timeout')[0]->lock_timeout)->toBe('6789ms');

    app(ConnectionTimeouts::class)->reset($connection);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');

it('actually sets the timeout on MySQL', function () {
    $connection = DB::connection();

    app(ConnectionTimeouts::class)->apply($connection, 12345, 6000);

    expect((int) $connection->select('select @@session.max_execution_time as v')[0]->v)->toBe(12345)
        ->and((int) $connection->select('select @@session.innodb_lock_wait_timeout as v')[0]->v)->toBe(6);

    app(ConnectionTimeouts::class)->reset($connection);
})->skip(fn () => ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'MySQL only');

it('sets a busy timeout on SQLite', function () {
    $connection = DB::connection();

    app(ConnectionTimeouts::class)->apply($connection, null, 4321);

    expect((int) $connection->select('PRAGMA busy_timeout')[0]->timeout)->toBe(4321);
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'SQLite only');

it('shrugs off a server that refuses the setting', function () {
    // An unsupported driver must not stop the backfill starting.
    $connection = DB::connection();

    app(ConnectionTimeouts::class)->apply($connection, -1, -1);
    app(ConnectionTimeouts::class)->reset($connection);

    expect(true)->toBeTrue();
});
