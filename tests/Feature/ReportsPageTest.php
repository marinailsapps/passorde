<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('opens the reports page for every role', function (bool $admin) {
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();

    actingAs($user)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Index'));
})->with(['sales user' => [false], 'admin' => [true]]);

it('sends guests to the login page', function () {
    get('/reports')->assertRedirect('/login');
});
