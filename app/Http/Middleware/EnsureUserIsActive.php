<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out a user who was deactivated after logging in, on their next request:
 * pages go to the login page, API calls get 401 (the phone then wipes its data
 * or lists its unsent orders, see resources/js/offline/runtime.ts).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // The phone's outbox and mirror must see 401, not a login page behind a redirect.
            if ($request->expectsJson()) {
                return response()->json(['message' => __('This account has been deactivated.')], 401);
            }

            return redirect()->route('login')->with('error', __('This account has been deactivated.'));
        }

        return $next($request);
    }
}
