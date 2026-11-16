<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->user = User::factory()->create());

it('renders the order list and the wizard with enabled paytypes', function () {
    Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    Paytype::create(['evoliz_paytype_id' => 1, 'label' => 'PayPal', 'is_enabled' => false]);

    actingAs($this->user)->get('/orders')->assertInertia(fn (AssertableInertia $page) => $page->component('Orders/Index'));
    actingAs($this->user)->get('/orders/new')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Orders/Wizard')
        ->where('order', null)
        ->has('paytypes', 1));
});

it('prefills the wizard from an order to duplicate', function () {
    $source = Order::factory()->for($this->user)->status(OrderStatus::Sent)->create();

    actingAs($this->user)->get("/orders/new?from={$source->uuid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('template.uuid', $source->uuid));

    $foreign = Order::factory()->create();
    actingAs($this->user)->get("/orders/new?from={$foreign->uuid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('template', null));
});

it('opens the editor only for editable orders', function () {
    $pending = Order::factory()->for($this->user)->create();
    $sent = Order::factory()->for($this->user)->status(OrderStatus::Sent)->create();

    actingAs($this->user)->get("/orders/{$pending->uuid}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Orders/Wizard')->where('order.uuid', $pending->uuid));
    actingAs($this->user)->get("/orders/{$sent->uuid}/edit")->assertRedirect("/orders/{$sent->uuid}");
});

it('shows an order to its author only', function () {
    $mine = Order::factory()->for($this->user)->create();
    $other = Order::factory()->create();

    actingAs($this->user)->get("/orders/{$mine->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->component('Orders/Show'));
    actingAs($this->user)->get("/orders/{$other->uuid}")->assertForbidden();
});

it('renders all orders for admins with the authors who ordered', function () {
    Order::factory()->for($this->user)->create();
    User::factory()->create(); // never ordered

    actingAs(User::factory()->admin()->create())->get('/admin/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Orders/Index')
            ->has('authors', 1)
            ->where('authors.0.id', $this->user->id));
});
