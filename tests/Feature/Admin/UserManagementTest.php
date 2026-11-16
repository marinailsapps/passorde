<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

beforeEach(fn () => $this->admin = User::factory()->admin()->create(['name' => 'Admin']));

it('lists users', function () {
    User::factory()->create(['name' => 'Ysolde Commerciale']);

    actingAs($this->admin)->get('/admin/users')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Users/Index')
            ->has('users', 2)
            ->missing('users.0.password'));
});

it('creates a user and flashes the password once', function () {
    $response = actingAs($this->admin)->post('/admin/users', [
        'name' => 'Paul Vendeur',
        'login' => 'Paul',
        'email' => '',
        'phone' => '06 00 00 00 00',
        'role' => 'user',
        'is_active' => true,
        'password' => 'initial-password-9',
    ]);

    $user = User::query()->where('login', 'paul')->firstOrFail();
    $response->assertRedirect("/admin/users/{$user->id}/edit")
        ->assertSessionHas('password', ['user' => 'Paul Vendeur', 'value' => 'initial-password-9']);

    expect($user->email)->toBeNull()
        ->and($user->role)->toBe(Role::User)
        ->and($user->password)->toStartWith('$argon2id$')
        ->and(Hash::check('initial-password-9', $user->password))->toBeTrue();

    // Gone on the following request.
    actingAs($this->admin)->get("/admin/users/{$user->id}/edit")->assertOk();
    actingAs($this->admin)->get("/admin/users/{$user->id}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.password', null));
});

it('validates unique login and email, case-insensitively', function () {
    User::factory()->create(['login' => 'ysolde', 'email' => 'ysolde@example.test']);

    actingAs($this->admin)->post('/admin/users', [
        'name' => 'Autre Ysolde',
        'login' => 'YSOLDE',
        'email' => 'Ysolde@Example.test',
        'role' => 'user',
        'is_active' => true,
        'password' => 'initial-password-9',
    ])->assertSessionHasErrors(['login', 'email']);
});

it('rejects logins with spaces or accents', function () {
    actingAs($this->admin)->post('/admin/users', [
        'name' => 'Hélène',
        'login' => 'hélène b',
        'role' => 'user',
        'is_active' => true,
        'password' => 'initial-password-9',
    ])->assertSessionHasErrors('login');
});

it('updates a user', function () {
    $user = User::factory()->create();

    actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => 'Nouveau Nom',
        'login' => $user->login,
        'email' => 'nouveau@example.test',
        'phone' => null,
        'role' => 'admin',
        'is_active' => true,
    ])->assertRedirect("/admin/users/{$user->id}/edit");

    expect($user->fresh())
        ->name->toBe('Nouveau Nom')
        ->email->toBe('nouveau@example.test')
        ->role->toBe(Role::Admin);
});

it('ends every session of a deactivated user', function () {
    config()->set('session.driver', 'database');
    $user = User::factory()->create(['remember_token' => 'old-token']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'login' => $user->login,
        'role' => 'user',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('sets a password and shows it once', function () {
    $user = User::factory()->create(['name' => 'Ysolde']);

    actingAs($this->admin)->put("/admin/users/{$user->id}/password", ['password' => 'generated-pass-42'])
        ->assertSessionHas('password', ['user' => 'Ysolde', 'value' => 'generated-pass-42']);

    expect(Hash::check('generated-pass-42', $user->fresh()->password))->toBeTrue();
});

it('does not let an admin deactivate or demote themselves', function () {
    actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => 'Admin',
        'login' => $this->admin->login,
        'role' => 'user',
        'is_active' => false,
    ])->assertSessionHasErrors(['is_active', 'role']);
});

it('ignores a password sent with a profile update', function () {
    $user = User::factory()->create();

    actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'login' => $user->login,
        'role' => 'user',
        'is_active' => true,
        'password' => 'sneaky-password-1',
    ])->assertSessionHasErrors('password');
});

it('deactivates a user from the edit form, which sends an empty password', function () {
    $user = User::factory()->create(['password' => 'correct-horse-1']);
    $hash = $user->password;

    actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'login' => $user->login,
        'email' => '',
        'phone' => '',
        'role' => 'user',
        'is_active' => false,
        'password' => '',
    ])->assertSessionHasNoErrors()->assertRedirect("/admin/users/{$user->id}/edit");

    expect($user->fresh())
        ->is_active->toBeFalse()
        ->password->toBe($hash);
});

it('has no way to delete a user', function () {
    $deleteRoutes = array_filter(
        Route::getRoutes()->getRoutes(),
        fn (Illuminate\Routing\Route $route) => in_array('DELETE', $route->methods()) && str_contains($route->uri(), 'users'),
    );

    expect($deleteRoutes)->toBeEmpty();
});

it('keeps the one-time password out of the browser history', function () {
    $user = User::factory()->create(['name' => 'Ysolde']);
    actingAs($this->admin);
    $version = get('/admin/users')->viewData('page')['version'];
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

    put("/admin/users/{$user->id}/password", ['password' => 'generated-pass-42'])->assertRedirect();
    // The page showing it is encrypted in history; a background API call changes nothing.
    expect(get("/admin/users/{$user->id}/edit", $inertia)->json())->toMatchArray(['encryptHistory' => true, 'clearHistory' => false])
        ->and(getJson('/api/paytypes')->assertOk()->json('data'))->toBeArray();
    // The next page drops the history key, so Back cannot decrypt it.
    expect(get('/admin/users', $inertia)->json())->toMatchArray(['encryptHistory' => false, 'clearHistory' => true])
        ->and(get('/admin/users', $inertia)->json('clearHistory'))->toBeFalse();
});

it('searches clients and the catalogue with at most 100 characters', function () {
    actingAs($this->admin);

    get('/admin/clients?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
    get('/catalog?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
});
