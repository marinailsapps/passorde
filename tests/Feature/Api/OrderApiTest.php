<?php

use App\Enums\ActivityAction;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['company_name' => 'EXEMPLE AMBROISE-LOUP', 'postcode' => '52380', 'town' => 'BOISJOLI']);
    $this->bravo = Article::factory()->create(['reference' => 'BRAVO TOM POM', 'unit_price_ht' => 83, 'vat_rate' => 5.5, 'designation' => 'BRAVO TOM POMMIER / CAL : 6-8']);
    $this->motte = Article::factory()->create(['reference' => 'BRAVO MOTTE POM', 'unit_price_ht' => 94, 'vat_rate' => 5.5]);
    $this->sweat = Article::factory()->create(['reference' => 'TABLIER JARDIN', 'unit_price_ht' => 0, 'vat_rate' => 20, 'is_stocked' => false]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    $this->uuid = (string) Str::uuid();
    actingAs($this->user);
});

/**
 * @return array<string, mixed>
 */
function blPayload(): array
{
    // The invented order of tests/Feature/Orders/OrderTotalsTest.php: 343.00 HT, 18.87 VAT, 361.87 TTC, 9 units.
    return orderPayload(test()->client, [
        [test()->bravo, 3], [test()->bravo, 1, true], [test()->motte, 1], [test()->motte, 2, true], [test()->sweat, 2],
    ], test()->paytype);
}

it('creates an order with a number and its totals', function () {
    $response = putJson("/api/orders/{$this->uuid}", blPayload())->assertCreated();

    $response->assertJsonPath('order.uuid', $this->uuid)
        ->assertJsonPath('order.status', 'pending')
        ->assertJsonPath('order.totals.quantity', '9.00')
        ->assertJsonPath('order.totals.ht', '343.00')
        ->assertJsonPath('order.totals.vat', '18.87')
        ->assertJsonPath('order.totals.ttc', '361.87')
        ->assertJsonPath('order.lines.1.is_free', true)
        ->assertJsonPath('order.lines.1.unit_price_ht', '0.00')
        ->assertJsonPath('order.lines.0.designation', 'BRAVO TOM POMMIER / CAL : 6-8')
        ->assertJsonPath('order.can_edit', true)
        ->assertJsonPath('order.can_delete', true)
        ->assertJsonPath('possible_duplicate', null);

    expect($response->json('order.number'))->toMatch('/^C-\d{4}-0001$/');
    expect(ActivityLog::query()->where('action', ActivityAction::Created)->count())->toBe(1);
});

it('is idempotent: the same uuid twice is one order, the second call updates it', function () {
    $first = putJson("/api/orders/{$this->uuid}", blPayload())->assertCreated();
    putJson("/api/orders/{$this->uuid}", blPayload())->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(0);

    $changed = orderPayload($this->client, [[$this->bravo, 3]], $this->paytype, ['comment' => 'Livrer lundi']);
    putJson("/api/orders/{$this->uuid}", $changed)
        ->assertOk()
        ->assertJsonPath('order.number', $first->json('order.number'))
        ->assertJsonPath('order.totals.ht', '249.00')
        ->assertJsonCount(1, 'order.lines')
        ->assertJsonPath('order.comment', 'Livrer lundi');

    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(1);
});

it('takes designation and VAT from the catalogue, the price from the user', function () {
    $payload = blPayload();
    $payload['lines'][0]['unit_price_ht'] = 77.5;

    putJson("/api/orders/{$this->uuid}", $payload)
        ->assertJsonPath('order.lines.0.unit_price_ht', '77.50')
        ->assertJsonPath('order.lines.0.vat_rate', '5.50')
        ->assertJsonPath('order.lines.0.total_ht', '232.50');
});

it('forbids changing another user\'s order', function () {
    $other = Order::factory()->create(['uuid' => $this->uuid]);

    putJson("/api/orders/{$this->uuid}", blPayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'Cette commande appartient à un autre utilisateur.');
    deleteJson("/api/orders/{$this->uuid}")->assertForbidden();

    expect($other->fresh()->trashed())->toBeFalse();
});

it('refuses to change an order that is no longer pending', function (OrderStatus $status, string $label) {
    Order::factory()->for($this->user)->status($status)->create(['uuid' => $this->uuid]);

    putJson("/api/orders/{$this->uuid}", blPayload())
        ->assertConflict()
        ->assertJsonPath('message', "Cette commande ne peut plus être modifiée : son statut est « {$label} ».");

    // A rejected order can still be deleted (below).
    if ($status !== OrderStatus::Rejected) {
        deleteJson("/api/orders/{$this->uuid}")
            ->assertConflict()
            ->assertJsonPath('message', "Cette commande ne peut plus être modifiée : son statut est « {$label} ».");
    }
})->with([
    [OrderStatus::Approved, 'Validée'],
    [OrderStatus::Sent, 'Envoyée'],
    [OrderStatus::Rejected, 'Refusée'],
    [OrderStatus::Failed, 'En erreur'],
]);

it('lets an admin delete another user\'s rejected order, not a pending one', function () {
    $pending = Order::factory()->for($this->user)->create();
    Order::factory()->for($this->user)->status(OrderStatus::Rejected)->create(['uuid' => $this->uuid]);
    actingAs(User::factory()->admin()->create());

    getJson("/api/orders/{$pending->uuid}")->assertJsonPath('order.can_delete', false);
    deleteJson("/api/orders/{$pending->uuid}")->assertForbidden();

    getJson("/api/orders/{$this->uuid}")->assertJsonPath('order.can_delete', true);
    deleteJson("/api/orders/{$this->uuid}")->assertNoContent();
    deleteJson("/api/orders/{$this->uuid}")->assertNoContent();

    expect(Order::withTrashed()->where('uuid', $this->uuid)->sole()->trashed())->toBeTrue();
});

it('lets the author delete a rejected order', function () {
    Order::factory()->for($this->user)->status(OrderStatus::Rejected)->create(['uuid' => $this->uuid]);

    getJson("/api/orders/{$this->uuid}")
        ->assertJsonPath('order.can_edit', false)
        ->assertJsonPath('order.can_delete', true);
    getJson('/api/orders')->assertJsonPath('data.0.can_delete', true);
    deleteJson("/api/orders/{$this->uuid}")->assertNoContent();

    expect(Order::withTrashed()->sole()->trashed())->toBeTrue()
        ->and(ActivityLog::query()->where('action', ActivityAction::Deleted)->count())->toBe(1);
});

it('deletes a pending order of its author, and accepts a replayed delete', function () {
    putJson("/api/orders/{$this->uuid}", blPayload());

    deleteJson("/api/orders/{$this->uuid}")->assertNoContent();
    deleteJson("/api/orders/{$this->uuid}")->assertNoContent();

    expect(Order::withTrashed()->sole()->trashed())->toBeTrue()
        ->and(ActivityLog::query()->where('action', ActivityAction::Deleted)->count())->toBe(1);

    putJson("/api/orders/{$this->uuid}", blPayload())
        ->assertConflict()
        ->assertJsonPath('message', 'Cette commande a été supprimée.');
});

it('validates lines against the catalogue, in French', function () {
    $inactive = Article::factory()->create(['reference' => 'OLD VARIETY POM', 'is_active' => false]);

    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$inactive, 1]], $this->paytype))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.article_id' => 'L\'article OLD VARIETY POM n\'est plus au catalogue : retirez la ligne.']);

    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->bravo, 1, true]], $this->paytype))
        ->assertJsonValidationErrors(['lines.0.is_free' => 'Un plant offert doit accompagner une ligne payante du même article.']);

    $payload = blPayload();
    $payload['lines'][0]['quantity'] = 0;
    $payload['lines'][2]['unit_price_ht'] = -1;
    putJson("/api/orders/{$this->uuid}", $payload)->assertJsonValidationErrors(['lines.0.quantity', 'lines.2.unit_price_ht']);

    putJson("/api/orders/{$this->uuid}", [...blPayload(), 'lines' => []])->assertJsonValidationErrors('lines');

    expect(Order::query()->count())->toBe(0);
});

it('accepts only enabled paytypes and active clients', function () {
    $disabled = Paytype::create(['evoliz_paytype_id' => 1, 'label' => 'PayPal', 'is_enabled' => false]);
    $inactiveClient = Client::factory()->create(['is_active' => false]);

    putJson("/api/orders/{$this->uuid}", [...blPayload(), 'paytype_id' => $disabled->id])->assertJsonValidationErrors('paytype_id');
    putJson("/api/orders/{$this->uuid}", [...blPayload(), 'client_id' => $inactiveClient->id])->assertJsonValidationErrors('client_id');
});

it('accepts an order without a payment method ("Non précisé")', function () {
    putJson("/api/orders/{$this->uuid}", [...blPayload(), 'paytype_id' => null])->assertCreated()->assertJsonPath('order.paytype', null);
    putJson('/api/orders/'.Str::uuid(), array_diff_key(blPayload(), ['paytype_id' => true]))->assertCreated();

    expect(Order::query()->whereNull('paytype_id')->count())->toBe(2);
});

it('creates a new client from the order, once, and flags a likely duplicate', function () {
    $clientUuid = (string) Str::uuid();
    $newClient = [
        'uuid' => $clientUuid,
        'company_name' => 'EXEMPLE AMBROISE LOUP',
        'contact_name' => 'Isaure',
        'address_line' => 'Le Bourg',
        'postcode' => '52380',
        'town' => 'Boisjoli',
        'phone' => '06 00 00 00 00',
        'email' => null,
        'extra_field' => 'LV-2093',
    ];

    $response = putJson("/api/orders/{$this->uuid}", orderPayload($newClient, [[$this->bravo, 2]], $this->paytype))
        ->assertCreated()
        ->assertJsonPath('possible_duplicate.id', $this->client->id)
        ->assertJsonPath('order.client.linked', false);

    $created = Client::query()->where('uuid', $clientUuid)->sole();
    expect($created->source)->toBe(ClientSource::App)
        ->and($created->created_by)->toBe($this->user->id)
        ->and($created->evoliz_client_id)->toBeNull()
        ->and($created->extra_field)->toBe('LV-2093');

    // Replayed with a corrected town: same client, updated.
    putJson("/api/orders/{$this->uuid}", orderPayload([...$newClient, 'town' => 'BOISJOLI'], [[$this->bravo, 2]], $this->paytype))->assertOk();
    expect(Client::query()->where('uuid', $clientUuid)->sole()->town)->toBe('BOISJOLI')
        ->and(Client::query()->count())->toBe(2);

    // The user picks the suggested existing client: the typed one goes away.
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->bravo, 2]], $this->paytype))
        ->assertOk()
        ->assertJsonPath('order.client.id', $this->client->id)
        ->assertJsonPath('possible_duplicate', null);
    expect(Client::query()->where('uuid', $clientUuid)->exists())->toBeFalse();
    expect($response->json('order.number'))->not->toBeNull();
});

it('does not flag a new client in another postcode', function () {
    $newClient = ['uuid' => (string) Str::uuid(), 'company_name' => 'EXEMPLE AMBROISE', 'postcode' => '38200', 'town' => 'LAMBERTOU'];

    putJson("/api/orders/{$this->uuid}", orderPayload($newClient, [[$this->bravo, 2]], $this->paytype))
        ->assertCreated()
        ->assertJsonPath('possible_duplicate', null);
});

it('requires postcode and town for a new client', function () {
    putJson("/api/orders/{$this->uuid}", orderPayload(['uuid' => (string) Str::uuid(), 'company_name' => 'SARL X'], [[$this->bravo, 2]], $this->paytype))
        ->assertJsonValidationErrors(['client.postcode', 'client.town']);
});

it('logs a duplicated order', function () {
    $source = Order::factory()->for($this->user)->create();

    putJson("/api/orders/{$this->uuid}", [...blPayload(), 'duplicated_from' => $source->uuid])->assertCreated();

    expect(ActivityLog::query()->where('action', ActivityAction::Duplicated)->sole()->meta_json)
        ->toBe(['from_uuid' => $source->uuid, 'from_number' => $source->number]);
});

it('lists only the caller\'s orders with filters', function () {
    $mine = Order::factory()->for($this->user)->for($this->client)->create(['created_at' => '2026-09-10 10:00:00']);
    $sent = Order::factory()->for($this->user)->status(OrderStatus::Sent)->create(['created_at' => '2026-08-01 10:00:00']);
    Order::factory()->create(); // someone else's
    Order::factory()->for($this->user)->create()->delete();

    getJson('/api/orders')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.uuid', $mine->uuid);
    getJson('/api/orders?status=sent')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $sent->uuid);
    getJson('/api/orders?from=2026-09-01&to=2026-09-30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine->uuid);
    getJson('/api/orders?q=exemple')->assertJsonCount(1, 'data')->assertJsonPath('data.0.client.company_name', 'EXEMPLE AMBROISE-LOUP');
    getJson('/api/orders?status=unknown')->assertJsonValidationErrors('status');
});

it('shows an order to its author and to admins only', function () {
    $order = Order::factory()->for($this->user)->create();
    $other = Order::factory()->create();

    getJson("/api/orders/{$order->uuid}")->assertOk()->assertJsonPath('order.number', $order->number);
    getJson("/api/orders/{$other->uuid}")->assertForbidden();

    actingAs(User::factory()->admin()->create());
    getJson("/api/orders/{$other->uuid}")->assertOk()->assertJsonPath('order.can_edit', true);
});

it('rejects guests with 401', function () {
    auth()->logout();

    getJson('/api/orders')->assertUnauthorized();
});

it('refuses totals beyond the money columns with a 422, not a 500', function () {
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->bravo, 99999]], $this->paytype, [
        'lines' => [['article_id' => $this->bravo->id, 'quantity' => 99999, 'unit_price_ht' => 99999, 'is_free' => false, 'position' => 0]],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['lines' => 'Le total de la commande dépasse 9 999 999,99 € : vérifiez les quantités et les prix.']);

    expect(Order::query()->count())->toBe(0);
});
