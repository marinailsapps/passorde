<?php

use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushClientJob;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    $this->seller = User::factory()->create();
    actingAs($this->seller);
    $this->uuid = (string) Str::uuid();
    $this->fields = ['company_name' => 'SARL DU VAL', 'contact_name' => 'Elouan Clairval', 'address_line' => '2 place Exemple', 'postcode' => '18960', 'town' => 'LAMBERTOU', 'phone' => '06 00 00 00 00', 'email' => null, 'extra_field' => null];
});

it('saves a client without an order and queues nothing', function () {
    putJson("/api/clients/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('client.company_name', 'SARL DU VAL')
        ->assertJsonPath('client.linked', false);

    Queue::assertNotPushed(PushClientJob::class);
    Http::assertNothingSent();
    expect(Client::query()->where('uuid', $this->uuid)->value('source'))->toBe(ClientSource::App);
});

it('lets the author correct it until an order passes pending', function () {
    putJson("/api/clients/{$this->uuid}", $this->fields)->assertCreated();
    $client = Client::query()->where('uuid', $this->uuid)->firstOrFail();
    $order = Order::factory()->for($client)->create(['user_id' => $this->seller->id]);

    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'town' => 'GRENADIER'])->assertOk()->assertJsonPath('client.town', 'GRENADIER')->assertJsonPath('client.linked', false);

    $order->update(['status' => OrderStatus::Approved]);

    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'town' => 'MONTFICTIF'])->assertOk()->assertJsonPath('client.town', 'GRENADIER')->assertJsonPath('client.linked', true);
});

it('settles a typed client at approval, and the phones hear of it', function () {
    $client = Client::factory()->fromApp()->create(['created_by' => $this->seller->id, 'updated_at' => now()->subDay()]);
    $order = Order::factory()->for($client)->create(['user_id' => $this->seller->id]);
    expect($client->isSettled())->toBeFalse()->and($client->isOpenTypedClient())->toBeTrue();

    actingAs(User::factory()->admin()->create());
    postJson("/api/admin/orders/{$order->uuid}/approve")->assertOk()->assertJsonPath('order.client.linked', true);

    $client->refresh();
    expect($client->isSettled())->toBeTrue()
        ->and($client->isOpenTypedClient())->toBeFalse()
        ->and($client->updated_at->gt(now()->subMinute()))->toBeTrue();
});

it('calls an imported client settled, and a typed one in Evoliz mode only when linked', function () {
    expect(Client::factory()->imported()->create()->isSettled())->toBeTrue();

    evolizMode();
    $typed = Client::factory()->fromApp()->create();
    Order::factory()->for($typed)->status(OrderStatus::Approved)->create();

    expect($typed->isSettled())->toBeFalse()
        ->and($typed->isOpenTypedClient())->toBeTrue()
        ->and(Client::factory()->create()->isSettled())->toBeTrue();
});

it('types a client in an order and drops it when replaced before any decision', function () {
    $article = Article::factory()->imported()->create(['stock_qty' => 5]);
    $paytype = Paytype::query()->create(['label' => 'Virement', 'is_enabled' => true]);
    $orderUuid = (string) Str::uuid();

    putJson("/api/orders/{$orderUuid}", orderPayload([...$this->fields, 'uuid' => $this->uuid], [[$article, 2]], $paytype))->assertCreated();
    $typed = Client::query()->where('uuid', $this->uuid)->firstOrFail();
    expect($typed->created_by)->toBe($this->seller->id);

    $other = Client::factory()->imported()->create();
    putJson("/api/orders/{$orderUuid}", orderPayload($other, [[$article, 2]], $paytype))->assertOk();

    expect(Client::query()->whereKey($typed->id)->exists())->toBeFalse();
});
