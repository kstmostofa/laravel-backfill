<?php

namespace Kstmostofa\Backfill\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kstmostofa\Backfill\BackfillServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return array_filter([
            class_exists(\Livewire\LivewireServiceProvider::class)
                ? \Livewire\LivewireServiceProvider::class
                : null,
            BackfillServiceProvider::class,
        ]);
    }

    /**
     * Which engine the suite runs against. The cross-database guarantees —
     * the unique-index run lock, per-row savepoints, insertOrIgnore — mean
     * nothing unless they are exercised on each engine that claims them.
     */
    public static function driver(): string
    {
        return env('BACKFILL_DRIVER', 'sqlite');
    }

    protected function defineEnvironment($app): void
    {
        // Livewire renders through the encrypter, so the app needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig());

        // Routes are registered at boot, so this has to be set before the app
        // boots rather than in a test's beforeEach. The Authorize middleware
        // still gates every request.
        $app['config']->set('backfill.dashboard.enabled', true);

        $app['config']->set('backfill.path', __DIR__.'/Fixtures/Backfills');
        $app['config']->set('backfill.connection', null);
        $app['config']->set('backfill.batch_size', 100);
        $app['config']->set('backfill.sleep_ms', 0);
        $app['config']->set('backfill.stale_after', 120);
    }

    protected function connectionConfig(): array
    {
        return match (static::driver()) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('BACKFILL_MYSQL_HOST', '127.0.0.1'),
                'port' => env('BACKFILL_MYSQL_PORT', '3306'),
                'database' => env('BACKFILL_MYSQL_DATABASE', 'laravel_backfill_test'),
                'username' => env('BACKFILL_MYSQL_USERNAME', 'root'),
                'password' => env('BACKFILL_MYSQL_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('BACKFILL_PGSQL_HOST', '127.0.0.1'),
                'port' => env('BACKFILL_PGSQL_PORT', '5432'),
                'database' => env('BACKFILL_PGSQL_DATABASE', 'laravel_backfill_test'),
                'username' => env('BACKFILL_PGSQL_USERNAME', 'postgres'),
                'password' => env('BACKFILL_PGSQL_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => $this->databasePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    /**
     * Overridden by tests that fork, which need a database both processes can
     * reach — an in-memory one would die with the connection.
     */
    protected function databasePath(): string
    {
        return ':memory:';
    }

    protected function defineDatabaseMigrations(): void
    {
        // A server-backed database outlives the test, so start each one from
        // bare metal rather than inheriting the last test's rows.
        if (static::driver() !== 'sqlite') {
            Schema::dropAllTables();
        }

        $this->artisan('migrate')->run();

        Schema::create('bf_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->unsignedInteger('process_count')->default(0);
            $table->timestamps();
        });

        Schema::create('bf_docs', function (Blueprint $table) {
            $table->string('uid')->primary();
            $table->string('body')->nullable();
            $table->unsignedInteger('done')->default(0);
        });

        // The unique index here is the point: it lets a fixture fail a row with
        // a real database error rather than a PHP exception, which is the only
        // thing that puts PostgreSQL into its aborted-transaction state.
        Schema::create('bf_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable()->unique();
        });
    }
}
