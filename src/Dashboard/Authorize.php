<?php

namespace Kstmostofa\Backfill\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        if (! Dashboard::check($request)) {
            throw new AccessDeniedHttpException(
                'Not authorised to view backfills. Grant access with Dashboard::auth().'
            );
        }

        return $next($request);
    }
}
