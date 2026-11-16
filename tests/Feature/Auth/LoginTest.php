<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withServerVariables;

beforeEach(function () {
    $this->user = User::factory()->create([
        'login' => 'ysolde',
        'email' => 'ysolde@example.test',
        'password' => 'correct-horse-1',
    ]);
});

it('shows the login page', function () {
    get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('logs in with the login handle, case-insensitively', function () {
    post('/login', ['identifier' => ' Ysolde ', 'password' => 'correct-horse-1'])->assertRedirect('/');

    assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('logs in with the email address', function () {
    post('/login', ['identifier' => 'YSOLDE@example.test', 'password' => 'correct-horse-1'])->assertRedirect('/');

    assertAuthenticatedAs($this->user);
});

it('always sets a remember-me cookie', function () {
    $response = post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1']);

    $remember = collect($response->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
    expect($remember)->not->toBeNull();
});

it('rejects a wrong password', function () {
    post('/login', ['identifier' => 'ysolde', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['identifier' => 'Identifiant ou mot de passe incorrect.']);

    assertGuest();
});

it('ends idle sessions after 30 days; remember-me signs back in', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain("\nSESSION_LIFETIME=43200\n")
        ->and(config('session.expire_on_close'))->toBeFalse()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

it('hashes passwords with argon2id', function () {
    expect($this->user->getAuthPassword())->toStartWith('$argon2id$');
});

it('logs out', function () {
    actingAs($this->user)->post('/logout')->assertRedirect('/login');

    assertGuest();
});

it('blocks login for an inactive user with the same answer as a wrong password', function () {
    $this->user->update(['is_active' => false]);

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])
        ->assertSessionHasErrors(['identifier' => 'Identifiant ou mot de passe incorrect.']);

    assertGuest();
});

it('checks a password hash for unknown logins too, so timing does not reveal them', function () {
    $hash = Hash::spy();

    post('/login', ['identifier' => 'nobody', 'password' => 'whatever-1'])
        ->assertSessionHasErrors(['identifier' => 'Identifiant ou mot de passe incorrect.']);

    $hash->shouldHaveReceived('check')->once();
});

it('logs out a user who is deactivated while signed in, on the next request', function () {
    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1']);
    get('/')->assertOk();

    DB::table('users')->where('id', $this->user->id)->update(['is_active' => false]);
    // A real next request reloads the user from the session; drop the cached one.
    app('auth')->forgetGuards();

    get('/')->assertRedirect('/login')->assertSessionHas('error', 'Ce compte a été désactivé.');
    assertGuest();
});

it('answers 401 to the API of a user deactivated while signed in, so the phone stops', function () {
    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1']);
    getJson('/api/paytypes')->assertOk();

    DB::table('users')->where('id', $this->user->id)->update(['is_active' => false]);
    app('auth')->forgetGuards();

    // Not a redirect to the login page, which fetch() would follow to a 200.
    putJson('/api/orders/0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10', [])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Ce compte a été désactivé.');
    assertGuest();
    getJson('/api/paytypes')->assertUnauthorized();
});

it('limits login attempts per IP', function () {
    freezeTime();
    foreach (range(1, 5) as $attempt) {
        post('/login', ['identifier' => "user{$attempt}", 'password' => 'wrong'])->assertSessionHasErrors('identifier');
    }

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])
        ->assertSessionHasErrors(['identifier' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => 60])]);

    assertGuest();
});

it('limits login attempts per login across IPs', function () {
    foreach (range(1, 5) as $attempt) {
        withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
            ->post('/login', ['identifier' => 'ysolde', 'password' => 'wrong'])
            ->assertSessionHasErrors('identifier');
    }

    withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])
        ->assertSessionHasErrors('identifier');

    assertGuest();
});

it('never counts successful logins: two people sign in from the same office IP', function () {
    $julie = User::factory()->create(['login' => 'julie', 'password' => 'correct-horse-2']);
    foreach (range(1, 4) as $attempt) {
        post('/login', ['identifier' => "typo{$attempt}", 'password' => 'wrong'])->assertSessionHasErrors('identifier');
    }

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])->assertRedirect('/');
    post('/logout');
    post('/login', ['identifier' => 'julie', 'password' => 'correct-horse-2'])->assertRedirect('/');

    assertAuthenticatedAs($julie);
    expect(RateLimiter::attempts('login|ip:127.0.0.1'))->toBe(0)
        ->and(RateLimiter::attempts('login|id:julie'))->toBe(0);
});

it('still blocks the IP after 5 failures, whatever the logins tried', function () {
    freezeTime();
    foreach (range(1, 5) as $attempt) {
        post('/login', ['identifier' => 'ysolde', 'password' => "wrong-{$attempt}"])->assertSessionHasErrors('identifier');
    }

    post('/login', ['identifier' => 'someone-else', 'password' => 'whatever'])
        ->assertSessionHasErrors(['identifier' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => 60])]);
    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])->assertSessionHasErrors('identifier');

    assertGuest();
});

it('takes the client IP from the trusted proxy only', function () {
    withServerVariables(['REMOTE_ADDR' => '172.27.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])
        ->post('/login', ['identifier' => 'ysolde', 'password' => 'wrong']);
    withServerVariables(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8'])
        ->post('/login', ['identifier' => 'ysolde', 'password' => 'wrong']);

    expect(RateLimiter::attempts('login|ip:203.0.113.7'))->toBe(1)
        ->and(RateLimiter::attempts('login|ip:172.27.0.4'))->toBe(0)
        ->and(RateLimiter::attempts('login|ip:198.51.100.1'))->toBe(1)
        ->and(RateLimiter::attempts('login|ip:203.0.113.8'))->toBe(0);
});

it('generates https URLs in production', function () {
    app()->detectEnvironment(fn () => 'production');
    (new AppServiceProvider(app()))->boot();

    expect(url('/login'))->toStartWith('https://')
        ->and(route('password.reset', ['token' => 't']))->toStartWith('https://');
});
