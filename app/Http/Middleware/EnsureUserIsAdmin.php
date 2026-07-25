<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to admin users. Assumes an upstream `auth:sanctum`, so an
 * unauthenticated request is a 401 there; a non-admin authenticated user is a 403.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Admin access required.');

        return $next($request);
    }
}
