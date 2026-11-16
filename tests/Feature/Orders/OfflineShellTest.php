<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('serves the offline app shell without any user data', function () {
    actingAs(User::factory()->create(['name' => 'Ysolde Dupont']));

    $response = get('/app-shell')->assertOk();

    expect($response->getContent())
        ->toContain('Offline\/Shell')
        ->toContain('/manifest.webmanifest')
        ->not->toContain('Ysolde Dupont');
});

it('serves the shell to guests too, so the service worker can precache it', function () {
    get('/app-shell')->assertOk();
});

it('lets the phone resolve an order the server does not know yet', function () {
    actingAs(User::factory()->create());
    $uuid = (string) Str::uuid();

    get("/orders/{$uuid}")->assertInertia(fn (AssertableInertia $page) => $page->component('Offline/Resolve'));
    get("/orders/{$uuid}/edit")->assertInertia(fn (AssertableInertia $page) => $page->component('Offline/Resolve'));
});

it('still answers 404 for deleted orders and 403 for other users\' orders', function () {
    $user = User::factory()->create();
    $deleted = Order::factory()->for($user)->create();
    $deleted->delete();
    $other = Order::factory()->create();
    actingAs($user);

    get("/orders/{$deleted->uuid}")->assertNotFound();
    get("/orders/{$other->uuid}")->assertForbidden();
});
