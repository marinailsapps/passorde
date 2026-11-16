<?php

namespace App\Http\Middleware;

use App\Support\SyncMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that only mean something with an Evoliz integration: 404 in file mode.
 */
class EnsureEvolizMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(SyncMode::isFile(), 404);

        return $next($request);
    }
}
