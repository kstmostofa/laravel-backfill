<?php

namespace Kstmostofa\Backfill\Dashboard;

use Closure;
use Illuminate\Http\Request;

/**
 * Who is allowed to see and drive backfills from the browser.
 *
 * The dashboard can start, pause and cancel data changes over production
 * tables, so it is closed by default everywhere except local development.
 * Opening it up is a deliberate act:
 *
 *     Dashboard::auth(fn ($request) => $request->user()?->isAdmin() === true);
 */
class Dashboard
{
    protected static ?Closure $authUsing = null;

    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        if (static::$authUsing !== null) {
            return (bool) call_user_func(static::$authUsing, $request);
        }

        return app()->environment('local');
    }

    /**
     * Only for tests that need to put the gate back how they found it.
     */
    public static function forgetAuth(): void
    {
        static::$authUsing = null;
    }

    public static function enabled(): bool
    {
        return (bool) config('backfill.dashboard.enabled', false)
            && class_exists(\Livewire\Livewire::class);
    }

    public static function path(): string
    {
        return trim((string) config('backfill.dashboard.path', 'backfills'), '/');
    }

    /**
     * @return array<int, string>
     */
    public static function middleware(): array
    {
        return array_merge(
            (array) config('backfill.dashboard.middleware', ['web']),
            [Authorize::class],
        );
    }
}
