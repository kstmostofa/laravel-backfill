<?php

namespace Kstmostofa\Backfill;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kstmostofa\Backfill\Commands\CancelBackfillCommand;
use Kstmostofa\Backfill\Commands\ListBackfillsCommand;
use Kstmostofa\Backfill\Commands\MakeBackfillCommand;
use Kstmostofa\Backfill\Commands\PauseBackfillCommand;
use Kstmostofa\Backfill\Commands\ResumeBackfillCommand;
use Kstmostofa\Backfill\Commands\RetryFailedBackfillCommand;
use Kstmostofa\Backfill\Commands\RunBackfillCommand;
use Kstmostofa\Backfill\Commands\StatusBackfillCommand;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\LockManager;
use Kstmostofa\Backfill\Runner\ShutdownSignals;
use Kstmostofa\Backfill\Support\MigrationGuard;

class BackfillServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backfill.php', 'backfill');

        $this->app->singleton(BackfillRegistry::class);
        $this->app->singleton(LockManager::class);
        $this->app->singleton(ShutdownSignals::class);
        $this->app->singleton(BackfillRunner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Backfills must never run inside a migration: migrations run
        // synchronously during deploy, so a multi-million row change there
        // stalls the pipeline and risks a statement timeout.
        Event::listen(MigrationsStarted::class, fn () => MigrationGuard::enter());
        Event::listen(MigrationsEnded::class, fn () => MigrationGuard::leave());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/backfill.php' => config_path('backfill.php'),
            ], 'backfill-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'backfill-migrations');

            $this->commands([
                MakeBackfillCommand::class,
                ListBackfillsCommand::class,
                RunBackfillCommand::class,
                StatusBackfillCommand::class,
                PauseBackfillCommand::class,
                ResumeBackfillCommand::class,
                CancelBackfillCommand::class,
                RetryFailedBackfillCommand::class,
            ]);
        }
    }
}
