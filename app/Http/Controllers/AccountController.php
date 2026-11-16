<?php

namespace App\Http\Controllers;

use App\Enums\SyncKind;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Account', [
            'lastSyncs' => collect(SyncKind::cases())->mapWithKeys(fn (SyncKind $kind) => [
                $kind->value => SyncRun::lastSucceeded($kind)?->finished_at?->toIso8601String(),
            ]),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        abort_if($user === null, 403);

        // A stolen phone stays signed in otherwise: end every other session.
        $user->password = $validated['password'];
        $user->endSessions(keepSessionId: $request->session()->getId());
        $user->save();

        // This phone's remember-me cookie carried the old token: issue a new one.
        Auth::login($user, remember: true);

        return back()->with('success', __('Password changed.'));
    }
}
