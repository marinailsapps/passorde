<?php

use App\Enums\ActivityAction;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    $this->seller = User::factory()->create(['name' => 'Ysolde']);
    $this->operator = User::factory()->admin()->create(['name' => 'Oriane']);
    $this->client = Client::factory()->create(['company_name' => 'EXEMPLE AMBROISE', 'postcode' => '46380', 'town' => 'BOISJOLI', 'evoliz_client_id' => 7000160]);
    $this->major = Article::factory()->create(['reference' => 'BRAVO TOM POM', 'unit_price_ht' => 32, 'vat_rate' => 5.5]);
    $this->motte = Article::factory()->create(['reference' => 'BRAVO MOTTE POM', 'unit_price_ht' => 32, 'vat_rate' => 5.5]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    $this->uuid = (string) Str::uuid();
});

/** A pending order of the seller: 10 units of $article. */
function sellersOrder(?Article $article = null, ?Client $client = null): Order
{
    actingAs(test()->seller);
    putJson('/api/orders/'.test()->uuid, orderPayload($client ?? test()->client, [[$article ?? test()->major, 10]], test()->paytype))->assertCreated();

    return Order::query()->where('uuid', test()->uuid)->firstOrFail();
}

it('offers "Modifier" to the operator on a seller\'s pending order, and opens the editor', function () {
    $order = sellersOrder();

    actingAs($this->operator);
    getJson("/api/orders/{$this->uuid}")->assertOk()->assertJsonPath('order.can_edit', true);
    getJson('/api/admin/orders')->assertOk()->assertJsonPath('data.0.can_edit', true);
    get("/orders/{$order->uuid}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Orders/Wizard')->where('order.uuid', $order->uuid)->where('order.author.name', 'Ysolde'));
});

it('lets the operator correct a seller\'s order: the order stays the seller\'s', function () {
    $order = sellersOrder();

    actingAs($this->operator);
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->major, 12]], $this->paytype))
        ->assertOk()
        ->assertJsonPath('order.number', $order->number)
        ->assertJsonPath('order.author.name', 'Ysolde')
        ->assertJsonPath('order.status', 'pending')
        ->assertJsonPath('order.totals.quantity', '12.00')
        ->assertJsonPath('order.can_approve', true);

    expect($order->fresh()->user_id)->toBe($this->seller->id);

    $log = ActivityLog::query()->where('action', ActivityAction::Updated)->where('order_id', $order->id)->sole();
    expect($log->user_id)->toBe($this->operator->id);
});

it('refuses the operator\'s edit once the order has left pending', function (OrderStatus $status) {
    $order = sellersOrder();
    $order->update(['status' => $status]);

    actingAs($this->operator);
    getJson("/api/orders/{$this->uuid}")->assertJsonPath('order.can_edit', false);
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->major, 12]], $this->paytype))->assertConflict();
    get("/orders/{$order->uuid}/edit")->assertRedirect("/orders/{$order->uuid}");

    expect($order->fresh()->total_quantity)->toBe('10.00');
})->with([[OrderStatus::Approved], [OrderStatus::Sent], [OrderStatus::Rejected], [OrderStatus::Failed]]);

it('keeps sellers off each other\'s orders, and the operator from deleting a pending one', function () {
    $order = sellersOrder();

    actingAs(User::factory()->create());
    getJson("/api/orders/{$this->uuid}")->assertForbidden();
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->major, 12]], $this->paytype))->assertForbidden();
    get("/orders/{$order->uuid}/edit")->assertRedirect("/orders/{$order->uuid}");

    actingAs($this->operator);
    getJson("/api/orders/{$this->uuid}")->assertJsonPath('order.can_delete', false);
    deleteJson("/api/orders/{$this->uuid}")->assertForbidden();
});

it('reaches the seller\'s phone even when only a line changed and the totals did not', function () {
    Carbon::setTestNow('2026-11-05 09:00:00');
    $order = sellersOrder($this->major);
    $before = $order->updated_at;

    Carbon::setTestNow('2026-11-05 10:00:00');
    actingAs($this->operator);
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->motte, 10]], $this->paytype))->assertOk();
    expect($order->fresh()->total_ht)->toBe($order->total_ht);

    Carbon::setTestNow('2026-11-05 10:00:01');
    actingAs($this->seller);
    getJson('/api/orders?updated_since='.urlencode($before->copy()->addMinutes(30)->toIso8601String()))
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $this->uuid)
        ->assertJsonPath('data.0.lines.0.reference', 'BRAVO MOTTE POM');

    Carbon::setTestNow();
});

it('drops the client the seller typed when the operator picks the existing one instead', function () {
    actingAs($this->seller);
    $typed = ['uuid' => (string) Str::uuid(), 'company_name' => 'SARL DOUBLON', 'postcode' => '46380', 'town' => 'BOISJOLI'];
    $payload = orderPayload($this->client, [[$this->major, 10]], $this->paytype);
    unset($payload['client_id']);
    putJson("/api/orders/{$this->uuid}", [...$payload, 'client' => $typed])->assertCreated();
    expect(Client::query()->where('uuid', $typed['uuid'])->where('source', ClientSource::App)->exists())->toBeTrue();

    actingAs($this->operator);
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->major, 10]], $this->paytype))
        ->assertOk()
        ->assertJsonPath('order.client.id', $this->client->id);

    expect(Client::query()->where('uuid', $typed['uuid'])->exists())->toBeFalse();
});

it('leaves a seller\'s client correction to the seller when the operator changes only the lines, and records the operator\'s own', function () {
    actingAs($this->seller);
    $payload = orderPayload($this->client, [[$this->major, 10]], $this->paytype);
    putJson("/api/orders/{$this->uuid}", [...$payload, 'client_changes' => ['town' => 'LAMBERTOU']])->assertCreated();

    actingAs($this->operator);
    putJson("/api/orders/{$this->uuid}", [...orderPayload($this->client, [[$this->major, 12]], $this->paytype), 'client_changes' => ['town' => 'LAMBERTOU']])->assertOk();
    $change = ClientChange::query()->sole();
    expect($change->user_id)->toBe($this->seller->id)
        ->and($change->status)->toBe(ClientChangeStatus::Pending);

    putJson("/api/orders/{$this->uuid}", [...orderPayload($this->client, [[$this->major, 12]], $this->paytype), 'client_changes' => ['town' => 'GRENADIER']])->assertOk();
    expect($change->fresh()->user_id)->toBe($this->operator->id);
});

it('keeps the whole order before and after every edit, and says what changed', function () {
    $order = sellersOrder();
    $cheque = Paytype::create(['evoliz_paytype_id' => 3, 'label' => 'Chèque', 'is_enabled' => true]);
    $other = Client::factory()->create(['company_name' => 'SARL AUTRE', 'evoliz_client_id' => 7000170]);

    actingAs($this->operator);
    $payload = orderPayload($other, [[$this->major, 12], [$this->major, 1, true], [$this->motte, 3]], $cheque);
    $payload['lines'][0]['unit_price_ht'] = 30;
    putJson("/api/orders/{$this->uuid}", [...$payload, 'comment' => 'Livrer lundi', 'client_changes' => ['town' => 'LAMBERTOU']])->assertOk();

    $log = ActivityLog::query()->where('action', ActivityAction::Updated)->sole();
    expect($log->meta_json['before'])->toMatchArray(['paytype' => 'Virement', 'total_quantity' => '10.00', 'client_changes' => null])
        ->and($log->meta_json['before']['client']['id'])->toBe($this->client->id)
        ->and($log->meta_json['before']['lines'])->toHaveCount(1)
        ->and($log->meta_json['before']['lines'][0])->toMatchArray(['reference' => 'BRAVO TOM POM', 'quantity' => '10.00', 'unit_price_ht' => '32.00', 'is_free' => false])
        ->and($log->meta_json['after'])->toMatchArray(['paytype' => 'Chèque', 'comment' => 'Livrer lundi', 'total_quantity' => '16.00'])
        ->and($log->meta_json['after']['lines'])->toHaveCount(3)
        ->and($log->meta_json['after']['client_changes']['town']['to'])->toBe('LAMBERTOU');

    $detail = getJson("/api/orders/{$this->uuid}")->assertOk()->json('order.activity.1.detail');
    expect(explode("\n", $detail))->toBe([
        'Client : EXEMPLE AMBROISE → SARL AUTRE',
        'Mode de paiement : Virement → Chèque',
        'Commentaire : — → Livrer lundi',
        'BRAVO TOM POM : quantité 10 → 12',
        'BRAVO TOM POM : prix 32,00 € → 30,00 €',
        'BRAVO TOM POM, plant offert ajoutée : 1 × 0,00 €',
        'BRAVO MOTTE POM ajoutée : 3 × 32,00 €',
        'Fiche client, ville : '.$other->town.' → LAMBERTOU',
    ]);

    // Replaying the same body changes nothing and logs nothing.
    putJson("/api/orders/{$this->uuid}", [...$payload, 'comment' => 'Livrer lundi', 'client_changes' => ['town' => 'LAMBERTOU']])->assertOk();
    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(1);

    // Each edit keeps its own step: a removed line and a dropped correction.
    putJson("/api/orders/{$this->uuid}", [...orderPayload($other, [[$this->major, 12]], $cheque), 'comment' => 'Livrer lundi'])->assertOk();
    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(2);
    expect(getJson("/api/orders/{$this->uuid}")->json('order.activity.2.detail'))
        ->toContain('BRAVO TOM POM, plant offert supprimée : 1 × 0,00 €')
        ->toContain('BRAVO MOTTE POM supprimée : 3 × 32,00 €')
        ->toContain('Fiche client, ville : correction retirée');

    expect($order->fresh()->user_id)->toBe($this->seller->id);
});

it('records the seller\'s own edits too, and hides the history from the seller', function () {
    sellersOrder();
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->major, 11]], $this->paytype))->assertOk()->assertJsonPath('order.activity', null);

    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->sole()->meta_json['after']['total_quantity'])->toBe('11.00');
});

it('shows no detail for edits recorded before the snapshots existed', function () {
    $order = sellersOrder();
    ActivityLog::record(ActivityAction::Updated, $this->seller, $order, ['number' => $order->number]);

    actingAs($this->operator);
    getJson("/api/orders/{$this->uuid}")->assertJsonPath('order.activity.1.action', 'updated')->assertJsonPath('order.activity.1.detail', null);
});

it('keeps a line comment apart from the catalogue designation, trims it and lists its changes', function () {
    actingAs($this->seller);
    $payload = orderPayload($this->client, [[$this->major, 10]], $this->paytype);
    $payload['lines'][0]['note'] = '  veste  ';
    putJson("/api/orders/{$this->uuid}", $payload)
        ->assertCreated()
        ->assertJsonPath('order.lines.0.note', 'veste')
        ->assertJsonPath('order.lines.0.designation', $this->major->designation);

    // The same body again changes nothing.
    putJson("/api/orders/{$this->uuid}", $payload)->assertOk();
    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(0);

    actingAs($this->operator);
    $payload['lines'][0]['note'] = 'sweat';
    putJson("/api/orders/{$this->uuid}", $payload)->assertOk();
    expect(getJson("/api/orders/{$this->uuid}")->json('order.activity.1.detail'))->toBe('BRAVO TOM POM : commentaire veste → sweat');

    // Without it, like any other part of the order, it is gone.
    unset($payload['lines'][0]['note']);
    putJson("/api/orders/{$this->uuid}", $payload)->assertOk()->assertJsonPath('order.lines.0.note', null);
    expect(getJson("/api/orders/{$this->uuid}")->json('order.activity.2.detail'))->toBe('BRAVO TOM POM : commentaire sweat → —');
});

it('refuses a line comment longer than 255 characters', function () {
    actingAs($this->seller);
    $payload = orderPayload($this->client, [[$this->major, 10]], $this->paytype);
    $payload['lines'][0]['note'] = str_repeat('x', 256);

    putJson("/api/orders/{$this->uuid}", $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.note');
});
