<?php

use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->author = User::factory()->create(['name' => 'Ysolde']);
    $this->order = Order::factory()->for($this->author)->withLines([[Article::factory()->create(['stock_qty' => 50]), 2]])->create();
});

it('approves a pending order and queues one push', function () {
    actingAs($this->admin);

    postJson("/api/admin/orders/{$this->order->uuid}/approve")
        ->assertOk()
        ->assertJsonPath('order.status', 'approved')
        ->assertJsonPath('order.approved_by', $this->admin->name)
        ->assertJsonPath('order.can_approve', false);

    Queue::assertPushed(PushOrderJob::class, fn ($job) => $job->orderId === $this->order->id);
    expect(ActivityLog::query()->where('action', ActivityAction::Approved)->sole()->user_id)->toBe($this->admin->id);

    postJson("/api/admin/orders/{$this->order->uuid}/approve")
        ->assertConflict()
        ->assertJsonPath('message', 'Seules les commandes en attente peuvent être validées.');
    Queue::assertPushedTimes(PushOrderJob::class, 1);
});

it('never queues the same push twice', function () {
    PushOrderJob::dispatch($this->order->id);
    PushOrderJob::dispatch($this->order->id);

    Queue::assertPushedTimes(PushOrderJob::class, 1);
});

it('rejects with a reason the author can read', function () {
    actingAs($this->admin);

    postJson("/api/admin/orders/{$this->order->uuid}/reject", [])->assertJsonValidationErrors('reason');
    postJson("/api/admin/orders/{$this->order->uuid}/reject", ['reason' => str_repeat('x', 501)])->assertJsonValidationErrors('reason');
    postJson("/api/admin/orders/{$this->order->uuid}/reject", ['reason' => 'Prix non validé'])
        ->assertOk()
        ->assertJsonPath('order.status', 'rejected');

    Queue::assertNothingPushed();
    postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertConflict();

    actingAs($this->author);
    getJson("/api/orders/{$this->order->uuid}")
        ->assertJsonPath('order.status_label', 'Refusée')
        ->assertJsonPath('order.rejected_reason', 'Prix non validé')
        ->assertJsonPath('order.can_edit', false)
        ->assertJsonPath('order.activity', null);
});

it('retries only failed orders', function () {
    actingAs($this->admin);
    postJson("/api/admin/orders/{$this->order->uuid}/retry")->assertConflict();

    $this->order->forceFill(['status' => OrderStatus::Failed, 'evoliz_error' => 'HTTP 500 — boom'])->save();
    postJson("/api/admin/orders/{$this->order->uuid}/retry")
        ->assertOk()
        ->assertJsonPath('order.status', 'approved')
        ->assertJsonPath('order.evoliz_error', null);

    Queue::assertPushedTimes(PushOrderJob::class, 1);
    expect(ActivityLog::query()->where('action', ActivityAction::Retried)->sole()->meta_json)->toBe(['previous_error' => 'HTTP 500 — boom']);
});

it('forbids approval actions to sales users', function () {
    actingAs($this->author);

    postJson("/api/admin/orders/{$this->order->uuid}/approve")->assertForbidden();
    postJson("/api/admin/orders/{$this->order->uuid}/reject", ['reason' => 'x'])->assertForbidden();
    postJson("/api/admin/orders/{$this->order->uuid}/retry")->assertForbidden();
    postJson('/api/admin/orders/approve', ['uuids' => [$this->order->uuid]])->assertForbidden();
    getJson('/api/admin/orders')->assertForbidden();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('approves in bulk, skipping orders that are not pending', function () {
    $second = Order::factory()->withLines([[Article::factory()->create(), 1]])->create();
    $sent = Order::factory()->status(OrderStatus::Sent)->create();
    actingAs($this->admin);

    postJson('/api/admin/orders/approve', ['uuids' => [$this->order->uuid, $second->uuid, $sent->uuid]])
        ->assertOk()
        ->assertJsonPath('approved', [$this->order->number, $second->number])
        ->assertJsonPath('skipped.0.number', $sent->number);

    Queue::assertPushedTimes(PushOrderJob::class, 2);
});

it('shows a failed push as "approved" to the author and as failed to the admin', function () {
    $this->order->forceFill(['status' => OrderStatus::Failed, 'evoliz_error' => 'HTTP 400 — items.0 : invalide'])->save();

    actingAs($this->author);
    getJson("/api/orders/{$this->order->uuid}")
        ->assertJsonPath('order.status', 'failed')
        ->assertJsonPath('order.display_status', 'approved')
        ->assertJsonPath('order.status_label', 'Validée · envoi en cours')
        ->assertJsonPath('order.evoliz_error', null);
    getJson('/api/orders?status=approved')->assertJsonCount(1, 'data');

    actingAs($this->admin);
    getJson("/api/orders/{$this->order->uuid}")
        ->assertJsonPath('order.display_status', 'failed')
        ->assertJsonPath('order.status_label', 'Échec de l\'envoi')
        ->assertJsonPath('order.evoliz_error', 'HTTP 400 — items.0 : invalide')
        ->assertJsonPath('order.can_retry', true);
});

it('shows the BL number to the author once sent', function () {
    $this->order->forceFill(['status' => OrderStatus::Sent, 'evoliz_delivery_number' => 'BL-20269001100'])->save();

    actingAs($this->author);
    getJson('/api/orders')->assertJsonPath('data.0.status_label', 'Envoyée · BL-20269001100');
});

it('lists every user\'s orders for admins, pending by default, with counts', function () {
    Order::factory()->status(OrderStatus::Sent)->create();
    Order::factory()->status(OrderStatus::Failed)->create();
    $client = Client::factory()->create(['company_name' => 'SARL FICTIF']);
    Order::factory()->for($client)->create();
    actingAs($this->admin);

    getJson('/api/admin/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('counts', ['pending' => 2, 'approved' => 0, 'rejected' => 0, 'sent' => 1, 'failed' => 1, 'all' => 4, 'conflict' => 0])
        ->assertJsonPath('data.1.author.name', 'Ysolde')
        ->assertJsonPath('data.0.can_approve', true);
    getJson('/api/admin/orders?status=all')->assertJsonCount(4, 'data');
    getJson('/api/admin/orders?status=failed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.display_status', 'failed');
    getJson("/api/admin/orders?user_id={$this->author->id}")->assertJsonCount(1, 'data')->assertJsonPath('counts.all', 1);
    getJson('/api/admin/orders?q=fictif')->assertJsonCount(1, 'data')->assertJsonPath('counts.all', 1);
});

it('shows the activity timeline to admins', function () {
    actingAs($this->admin);
    postJson("/api/admin/orders/{$this->order->uuid}/approve");

    getJson("/api/orders/{$this->order->uuid}")
        ->assertJsonPath('order.activity.0.action', 'approved')
        ->assertJsonPath('order.activity.0.label', 'Validée')
        ->assertJsonPath('order.activity.0.user', $this->admin->name);
});

it('gives admins pending and failed badges on the home page', function () {
    Order::factory()->status(OrderStatus::Failed)->create();

    actingAs($this->admin)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('badges', ['pending' => 1, 'failed' => 1, 'to_review' => 0]));
    actingAs($this->author)->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('badges', null));
});
