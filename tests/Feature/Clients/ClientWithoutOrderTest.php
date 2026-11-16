<?php

use App\Domain\Ops\HealthReport;
use App\Domain\Orders\OrderService;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushClientJob;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\Subject;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Sleep::fake();

    $this->seller = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->admin = User::factory()->admin()->create(['name' => 'Chef']);
    $this->uuid = (string) Str::uuid();
    $this->fields = [
        'company_name' => 'SARL du Val-Mary',
        'contact_name' => 'Elouan Clairval',
        'address_line' => '2 place Exemple',
        'postcode' => '85200',
        'town' => 'LAMBERTOU',
        'phone' => '06 00 00 00 00',
        'email' => 'elouan@example.test',
        'extra_field' => 'R-7Q2K',
    ];
});

/**
 * @param  array<string, mixed>  $attributes
 */
function typedClient(array $attributes = []): Client
{
    return Client::factory()->fromApp()->create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'SARL du Val-Mary',
        'contact_name' => 'Elouan Clairval',
        'email' => 'elouan@example.test',
        'address_line' => '2 place Exemple',
        'postcode' => '85200',
        'town' => 'LAMBERTOU',
        'phone' => '06 00 00 00 00',
        'created_by' => test()->seller->id,
        ...$attributes,
    ]);
}

/**
 * @return array<string, Closure>
 */
function evolizAcceptsClient(): array
{
    $created = [...evolizFixture('client-created'), 'clientid' => 10500001, 'code' => 'SARLDUVALM'];

    return [
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson($created, 201),
        // Passorde reads the client's contacts before writing one, and the client after.
        'GET /contacts-clients' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]),
        'POST /contacts-clients' => evolizJson(['contactid' => 610001], 201),
        'GET /clients/{id}' => evolizJson($created),
    ];
}

/**
 * @return list<Request>
 */
function requestsTo(string $method, string $path): array
{
    return Http::recorded(fn (Request $request) => $request->method() === $method
        && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))
        ->map(fn ($pair) => $pair[0])->values()->all();
}

it('saves a client without an order and queues it for Evoliz', function () {
    Queue::fake();
    actingAs($this->seller);

    $response = putJson("/api/clients/{$this->uuid}", $this->fields)->assertCreated();

    $client = Client::query()->where('uuid', $this->uuid)->sole();
    expect($client)
        ->company_name->toBe('SARL du Val-Mary')
        ->source->toBe(ClientSource::App)
        ->created_by->toBe($this->seller->id)
        ->is_active->toBeTrue()
        ->evoliz_client_id->toBeNull();
    expect($response->json('client'))->toMatchArray(['id' => $client->id, 'uuid' => $this->uuid, 'company_name' => 'SARL du Val-Mary', 'linked' => false]);
    Queue::assertPushed(PushClientJob::class, fn ($job) => $job->clientId === $client->id);
    expect(Order::query()->count())->toBe(0);
});

it('never makes a second client on a replay, and lets the author correct it until it is pushed', function () {
    Queue::fake();
    actingAs($this->seller);

    putJson("/api/clients/{$this->uuid}", $this->fields)->assertCreated();
    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'town' => 'GRENADIER'])->assertOk()->assertJsonPath('client.town', 'GRENADIER');
    expect(Client::query()->where('uuid', $this->uuid)->count())->toBe(1);

    Client::query()->where('uuid', $this->uuid)->update(['evoliz_client_id' => 10500001]);
    Queue::fake();
    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'town' => 'MONTFICTIF'])->assertOk()->assertJsonPath('client.town', 'GRENADIER');
    Queue::assertNotPushed(PushClientJob::class);
});

it('keeps another user from changing a typed client', function () {
    Queue::fake();
    actingAs($this->seller);
    putJson("/api/clients/{$this->uuid}", $this->fields)->assertCreated();

    actingAs(User::factory()->create());
    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'town' => 'GRENADIER'])->assertOk()->assertJsonPath('client.town', 'LAMBERTOU');
});

it('validates like the client of an order', function () {
    Queue::fake();
    actingAs($this->seller);

    putJson("/api/clients/{$this->uuid}", [...$this->fields, 'company_name' => '', 'postcode' => '152', 'email' => 'pas-un-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_name', 'postcode', 'email']);

    Queue::assertNothingPushed();
});

it('creates the client and its contact in Evoliz, with the same bodies as an order, and no BL', function () {
    $client = typedClient();
    scriptEvoliz(evolizAcceptsClient());

    PushClientJob::dispatchSync($client->id);

    expect($client->fresh())
        ->evoliz_client_id->toBe(10500001)
        ->evoliz_code->toBe('SARLDUVALM')
        ->evoliz_error->toBeNull();
    expect(json_decode(requestsTo('POST', '/clients')[0]->body(), true))->toBe([
        'type' => 'Professionnel',
        'name' => 'SARL du Val-Mary',
        'code' => 'SARLDUVALM',
        'vat_number' => 'NC',
        'address' => ['addr' => '2 place Exemple', 'postcode' => '85200', 'town' => 'LAMBERTOU', 'iso2' => 'FR'],
        'phone' => '06 00 00 00 00',
    ]);
    expect(json_decode(requestsTo('POST', '/contacts-clients')[0]->body(), true))->toBe([
        'clientid' => 10500001, 'lastname' => 'Elouan Clairval', 'email' => 'elouan@example.test', 'favorite' => true,
    ]);

    // Every request is logged with the client, not with an order.
    $logs = passordeRequests();
    expect($logs->pluck('kind')->all())->toBe(['client.lookup', 'client.create', 'contact.lookup', 'contact.create', 'client.read', 'contact.read'])
        ->and($logs->pluck('subject_type')->unique()->all())->toBe([Subject::CLIENT])
        ->and($logs->pluck('subject_id')->unique()->all())->toBe([$client->id]);
});

it('does nothing for a client already in Evoliz, or one from Evoliz', function () {
    scriptEvoliz([]);

    PushClientJob::dispatchSync(typedClient(['evoliz_client_id' => 10500001, 'evoliz_code' => 'SARLDUVALM'])->id);
    PushClientJob::dispatchSync(Client::factory()->fromApp()->create(['source' => ClientSource::Evoliz])->id);

    Http::assertNothingSent();
});

it('keeps Evoliz refusal on the client, for the admin and the digest', function () {
    $client = typedClient();
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson(['error' => 'Bad Request Error', 'message' => ['vat_number' => ['The vat number field is required.']]], 400),
    ]);

    PushClientJob::dispatchSync($client->id);

    expect($client->fresh())
        ->evoliz_client_id->toBeNull()
        ->evoliz_error->toBe('HTTP 400 — vat_number : The vat number field is required.');

    $section = collect(app(HealthReport::class)->sections())->firstWhere('title', 'Clients refusés par Evoliz (1)');
    expect($section['lines'] ?? null)->toBe(['SARL du Val-Mary · HTTP 400 — vat_number : The vat number field is required.']);
});

it('says so in test mode, without creating anything', function () {
    $client = typedClient();
    config()->set('passorde-connect.writes_enabled', false);
    scriptEvoliz(['GET /clients' => evolizJson(evolizFixture('clients-empty'))]);

    PushClientJob::dispatchSync($client->id);

    expect($client->fresh())
        ->evoliz_client_id->toBeNull()
        ->evoliz_error->toBe('Envoi Evoliz désactivé (mode test)');
    expect(requestsTo('POST', '/clients'))->toBe([]);
});

it('links the client when only the contact is refused, and the admin sends the contact again', function () {
    $client = typedClient();
    scriptEvoliz([
        ...evolizAcceptsClient(),
        'POST /contacts-clients' => fn (Request $request) => passordeRequests()->where('kind', 'contact.create')->where('status', 'failed')->isNotEmpty()
            ? evolizJson(['contactid' => 610001], 201)($request)
            : evolizJson(['error' => 'Bad Request Error', 'message' => ['email' => ['Invalid.']]], 400)($request),
    ]);

    PushClientJob::dispatchSync($client->id);

    expect($client->fresh())
        ->evoliz_client_id->toBe(10500001)
        ->evoliz_error->toBe('HTTP 400 — email : Invalid.');

    PushClientJob::dispatchSync($client->id);

    expect($client->fresh()->evoliz_error)->toBeNull();
    expect(passordeRequests()->where('kind', 'client.create')->count())->toBe(1);
});

it('reuses the pushed client for its orders, without a second client or contact', function () {
    $client = typedClient();
    $article = Article::factory()->create(['evoliz_article_id' => 7000240, 'unit_price_ht' => 53, 'vat_rate' => 5.5]);
    $delivery = evolizFixture('delivery-created');
    scriptEvoliz([
        ...evolizAcceptsClient(),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]),
        'POST /deliveries' => fn (Request $request) => evolizJson([...$delivery, 'external_document_number' => Order::query()->sole()->number], 201)($request),
    ]);
    PushClientJob::dispatchSync($client->id);

    $order = app(OrderService::class)->save($this->seller, (string) Str::uuid(), [
        'client_id' => $client->id,
        'lines' => [['article_id' => $article->id, 'quantity' => 1, 'unit_price_ht' => 53, 'is_free' => false, 'position' => 0]],
    ])['order'];
    $order->forceFill(['status' => OrderStatus::Approved, 'approved_by' => $this->admin->id, 'approved_at' => now()])->save();

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent);
    expect(json_decode(requestsTo('POST', '/deliveries')[0]->body(), true)['clientid'])->toBe(10500001);
    expect(passordeRequests()->whereIn('kind', ['client.create', 'contact.create'])->count())->toBe(2);
});

it('clears the error once an approved order has created the client', function () {
    $client = typedClient(['evoliz_error' => 'HTTP 503 — down']);
    $article = Article::factory()->create(['evoliz_article_id' => 7000240, 'unit_price_ht' => 53, 'vat_rate' => 5.5]);
    $order = app(OrderService::class)->save($this->seller, (string) Str::uuid(), [
        'client_id' => $client->id,
        'lines' => [['article_id' => $article->id, 'quantity' => 1, 'unit_price_ht' => 53, 'is_free' => false, 'position' => 0]],
    ])['order'];
    $order->forceFill(['status' => OrderStatus::Approved, 'approved_by' => $this->admin->id, 'approved_at' => now()])->save();

    $delivery = evolizFixture('delivery-created');
    $delivery['external_document_number'] = $order->number;
    scriptEvoliz([
        ...evolizAcceptsClient(),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]),
        'POST /deliveries' => evolizJson($delivery, 201),
    ]);
    PushOrderJob::dispatchSync($order->id);

    expect($client->fresh())
        ->evoliz_client_id->toBe(10500001)
        ->evoliz_error->toBeNull();
});

it('lets an admin send a typed client to Evoliz, once it is not there yet', function () {
    Queue::fake();
    $client = typedClient();
    $fromEvoliz = Client::factory()->create(['evoliz_client_id' => 7000160]);
    $linked = typedClient(['evoliz_client_id' => 10500009]);

    actingAs($this->admin);
    get("/admin/clients/{$client->id}")->assertInertia(fn ($page) => $page->where('client.can_push', true));
    get("/admin/clients/{$linked->id}")->assertInertia(fn ($page) => $page->where('client.can_push', false));

    post("/admin/clients/{$client->id}/push")->assertRedirect();
    Queue::assertPushed(PushClientJob::class, fn ($job) => $job->clientId === $client->id);

    post("/admin/clients/{$fromEvoliz->id}/push")->assertNotFound();
    post("/admin/clients/{$linked->id}/push")->assertNotFound();

    actingAs($this->seller);
    post("/admin/clients/{$client->id}/push")->assertForbidden();
});
