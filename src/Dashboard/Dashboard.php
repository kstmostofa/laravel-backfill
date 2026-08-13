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

    protected static ?Closure $operatorAuthUsing = null;

    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Who may use the operator panel. Separate from auth() on purpose: the
     * people who should paste order ids into a form are rarely the same people
     * who should be able to cancel a run half way through. Falls back to the
     * engineer gate when not set.
     */
    public static function operatorAuth(Closure $callback): void
    {
        static::$operatorAuthUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        if (static::$authUsing !== null) {
            return (bool) call_user_func(static::$authUsing, $request);
        }

        return app()->environment('local');
    }

    public static function checkOperator(Request $request): bool
    {
        if (static::$operatorAuthUsing !== null) {
            return (bool) call_user_func(static::$operatorAuthUsing, $request);
        }

        return static::check($request);
    }

    /**
     * Only for tests that need to put the gates back how they found them.
     */
    public static function forgetAuth(): void
    {
        static::$authUsing = null;
        static::$operatorAuthUsing = null;
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

    public static function operatorPath(): string
    {
        return trim((string) config('backfill.dashboard.operator_path', 'backfills/tasks'), '/');
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

    /**
     * @return array<int, string>
     */
    public static function operatorMiddleware(): array
    {
        return array_merge(
            (array) config('backfill.dashboard.middleware', ['web']),
            [AuthorizeOperator::class],
        );
    }
}
