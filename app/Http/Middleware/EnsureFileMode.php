<?php

namespace App\Http\Middleware;

use App\Support\SyncMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manual data (import, catalogue edits): with an Evoliz integration Evoliz holds
 * the catalogue and the next sync would undo them, so 403.
 */
class EnsureFileMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(SyncMode::isFile(), 403);

        return $next($request);
    }
}
