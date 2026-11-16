<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

dataset('admin pages', ['/admin/users', '/admin/users/create', '/admin/clients', '/admin/sync', '/admin/orders']);

it('forbids admin pages to sales users', function (string $url) {
    actingAs(User::factory()->create())->get($url)->assertForbidden();
})->with('admin pages');

it('redirects guests to the login page', function (string $url) {
    get($url)->assertRedirect('/login');
})->with('admin pages');

it('opens admin pages for admins', function (string $url) {
    actingAs(User::factory()->admin()->create())->get($url)->assertOk();
})->with('admin pages');

it('lets sales users reach the home page and order placeholders', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/')->assertOk();
    actingAs($user)->get('/orders/new')->assertOk();
    actingAs($user)->get('/orders')->assertOk();
});
