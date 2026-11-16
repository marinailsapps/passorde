<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\TurnstileToken;
use App\Support\AttemptLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $limiter = AttemptLimiter::for('login', $request, (string) $request->input('identifier'));
        $limiter->ensureNotLimited('identifier');

        try {
            $user = $this->authenticate($request);
        } catch (ValidationException $e) {
            $limiter->failed();

            throw $e;
        }

        $limiter->succeeded();
        $password = $request->string('password')->toString();

        if (Hash::needsRehash($user->getAuthPassword())) {
            $user->password = $password;
        }

        $user->last_login_at = now();
        $user->save();

        // Sessions end after 30 idle days; "remember me" is always on.
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * The active user these credentials belong to.
     *
     * @throws ValidationException
     */
    private function authenticate(Request $request): User
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            ...TurnstileToken::rules(),
        ]);

        $user = User::findByIdentifier($request->string('identifier')->toString());
        $password = $request->string('password')->toString();

        // Unknown logins cost the same hash check, and a deactivated account
        // gets the same answer, so neither reveals which logins exist.
        $valid = Hash::check($password, $user?->getAuthPassword() ?? $this->dummyHash());

        if ($user === null || ! $valid || ! $user->is_active) {
            throw ValidationException::withMessages(['identifier' => __('Invalid login or password.')]);
        }

        return $user;
    }

    /**
     * A hash with the current driver and cost, made once and cached.
     */
    private function dummyHash(): string
    {
        return (string) Cache::rememberForever('auth.dummy_hash.'.config('hashing.driver'), fn () => Hash::make(Str::random(40)));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
