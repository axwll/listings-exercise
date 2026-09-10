<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * These routes are user-scoped (saved searches, alerts) and have nothing
 * sensible to show without a resolved user. The demo-auth stub (see
 * ActAsDemoUser) resolves null until the database is seeded, so fail
 * predictably here rather than crashing deeper in the controller.
 */
class EnsureDemoUserIsSeeded
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user() === null, 503, 'No user found — run `php artisan migrate --seed`.');

        return $next($request);
    }
}
