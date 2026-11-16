<?php

namespace App\Http\Middleware;

use App\Support\EvolizState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passorde's own pages (the licence page): with Evoliz switched off nothing in
 * the app speaks of licences, so they answer 404.
 */
class EnsureEvolizEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(EvolizState::enabled(), 404);

        return $next($request);
    }
}
