<?php

namespace Kstmostofa\Backfill\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthorizeOperator
{
    public function handle(Request $request, Closure $next)
    {
        if (! Dashboard::checkOperator($request)) {
            throw new AccessDeniedHttpException(
                'Not authorised to run tasks. Grant access with Dashboard::operatorAuth().'
            );
        }

        return $next($request);
    }
}
