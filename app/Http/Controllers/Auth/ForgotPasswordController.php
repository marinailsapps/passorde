<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\TurnstileToken;
use App\Support\AttemptLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AttemptLimiter::for('password-reset', $request, (string) $request->input('identifier'))->hit('identifier');

        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            ...TurnstileToken::rules(),
        ]);

        $user = User::findByIdentifier($request->string('identifier')->toString());

        if ($user !== null && $user->is_active && $user->email !== null) {
            Password::sendResetLink(['email' => $user->email]);
        }

        // Same answer whether the account exists, is active or has an email.
        return back()->with('status', __('If an account with an email address matches, a reset link has been sent. Otherwise ask the administrator for a new password.'));
    }
}
