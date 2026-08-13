<?php

namespace Kstmostofa\Backfill;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kstmostofa\Backfill\Commands\CancelBackfillCommand;
use Kstmostofa\Backfill\Commands\ListBackfillsCommand;
use Kstmostofa\Backfill\Commands\MakeBackfillCommand;
use Kstmostofa\Backfill\Commands\PauseBackfillCommand;
use Kstmostofa\Backfill\Commands\ResumeBackfillCommand;
use Kstmostofa\Backfill\Commands\RetryFailedBackfillCommand;
use Kstmostofa\Backfill\Commands\RunBackfillCommand;
use Kstmostofa\Backfill\Commands\StatusBackfillCommand;
use Kstmostofa\Backfill\Dashboard\BackfillDashboard;
use Kstmostofa\Backfill\Dashboard\Dashboard;
use Kstmostofa\Backfill\Events\BackfillCompleted;
use Kstmostofa\Backfill\Events\BackfillFailed;
use Kstmostofa\Backfill\Events\BackfillPaused;
use Kstmostofa\Backfill\Notifications\BackfillNotifier;
use Kstmostofa\Backfill\Runner\BackfillRunner;
use Kstmostofa\Backfill\Runner\CircuitBreaker;
use Kstmostofa\Backfill\Runner\ConnectionTimeouts;
use Kstmostofa\Backfill\Runner\LagMonitor;
use Kstmostofa\Backfill\Runner\LockManager;
use Kstmostofa\Backfill\Runner\ProductionGuards;
use Kstmostofa\Backfill\Runner\ShutdownSignals;
use Kstmostofa\Backfill\Runner\Throttle;
use Kstmostofa\Backfill\Support\MigrationGuard;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

class BackfillServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/backfill.php', 'backfill');

        $this->app->singleton(BackfillRegistry::class);
        $this->app->singleton(LockManager::class);
        $this->app->singleton(ShutdownSignals::class);
        $this->app->singleton(ConnectionTimeouts::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(LagMonitor::class);
        $this->app->singleton(Throttle::class);
        $this->app->singleton(ProductionGuards::class);
        $this->app->singleton(BackfillRunner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backfill');

        $this->registerMigrationGuard();
        $this->registerNotifications();
        $this->registerDashboard();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

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

    /**
     * Backfills must never run inside a migration: migrations run
     * synchronously during deploy, so a multi-million row change there stalls
     * the pipeline and risks a statement timeout.
     */
    protected function registerMigrationGuard(): void
    {
        Event::listen(MigrationsStarted::class, fn () => MigrationGuard::enter());
        Event::listen(MigrationsEnded::class, fn () => MigrationGuard::leave());
    }

    protected function registerNotifications(): void
    {
        Event::listen(BackfillCompleted::class, [BackfillNotifier::class, 'handleCompleted']);
        Event::listen(BackfillFailed::class, [BackfillNotifier::class, 'handleFailed']);
        Event::listen(BackfillPaused::class, [BackfillNotifier::class, 'handlePaused']);
    }

    protected function registerDashboard(): void
    {
        // Livewire being installed is not enough — in a package test harness the
        // class can be autoloadable while its service provider was never
        // registered, and registering a component then blows up on a missing
        // binding.
        if (! class_exists(Livewire::class) || ! $this->app->providerIsLoaded(LivewireServiceProvider::class)) {
            return;
        }

        Livewire::component('backfill-dashboard', BackfillDashboard::class);

        if (! config('backfill.dashboard.enabled', false)) {
            return;
        }

        Route::group([
            'prefix' => Dashboard::path(),
            'middleware' => Dashboard::middleware(),
        ], function () {
            Route::get('/', fn () => view('backfill::layout'))->name('backfill.dashboard');
        });
    }

    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/backfill.php' => config_path('backfill.php'),
        ], 'backfill-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'backfill-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/backfill'),
        ], 'backfill-views');
    }
}
