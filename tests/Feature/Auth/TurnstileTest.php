<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    config()->set('services.turnstile.enabled', true);
    config()->set('services.turnstile.site_key', 'site-key');
    config()->set('services.turnstile.secret_key', 'secret-key');

    $this->user = User::factory()->create(['login' => 'ysolde', 'password' => 'correct-horse-1']);
});

it('shares the site key with the login page', function () {
    get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('turnstileSiteKey', 'site-key'));
});

it('does not share a site key when disabled', function () {
    config()->set('services.turnstile.enabled', false);

    get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('turnstileSiteKey', null));
});

it('logs in when Cloudflare accepts the token', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1', 'turnstile_token' => 'good-token'])
        ->assertRedirect('/');

    assertAuthenticatedAs($this->user);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'good-token');
});

it('reports a rejected token as a form error', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1', 'turnstile_token' => 'bad-token'])
        ->assertSessionHasErrors(['turnstile_token' => 'La vérification anti-robot a échoué. Réessayez.']);

    assertGuest();
});

it('requires the token when enabled', function () {
    Http::fake();

    post('/login', ['identifier' => 'ysolde', 'password' => 'correct-horse-1'])->assertSessionHasErrors('turnstile_token');

    Http::assertNothingSent();
    assertGuest();
});

it('protects the forgot password form', function () {
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    post('/forgot-password', ['identifier' => 'ysolde', 'turnstile_token' => 'bad-token'])->assertSessionHasErrors('turnstile_token');
});
