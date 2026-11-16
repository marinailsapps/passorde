<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin user management. Users are never deleted, only deactivated,
 * because orders will reference them.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $this->present($user)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', ['user' => null]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = User::create($validated);

        return redirect()->route('admin.users.edit', $user)
            ->with('success', __('User created.'))
            ->with('password', ['user' => $user->name, 'value' => $validated['password']]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', ['user' => $this->present($user)]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        // The form sends an empty "password" (it only has one when creating), which
        // reaches validated() as null: never let it overwrite the stored hash.
        $user->fill($request->safe()->except('password'));

        if ($user->isDirty('is_active') && ! $user->is_active) {
            $user->endSessions();
        }

        $user->save();

        return redirect()->route('admin.users.edit', $user)->with('success', __('User saved.'));
    }

    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user->password = $validated['password'];

        if (! $user->is($request->user())) {
            $user->endSessions();
        }

        $user->save();

        return redirect()->route('admin.users.edit', $user)
            ->with('success', __('Password set.'))
            ->with('password', ['user' => $user->name, 'value' => $validated['password']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'login' => $user->login,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
