<?php

use App\Domain\Orders\ApprovalService;
use App\Enums\ActivityAction;
use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\ApplyClientChangeJob;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\Subject;
use App\Integrations\Sync\SyncProvider;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Sleep::fake();

    $this->seller = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->admin = User::factory()->admin()->create(['name' => 'Chef']);
    $this->client = Client::factory()->create([
        'evoliz_client_id' => 7000160, 'evoliz_code' => 'EXEMPLEAMB', 'company_name' => 'EXEMPLE AMBROISE',
        'contact_name' => 'Ambroise Exemple', 'email' => 'jp@example.test',
        'address_line' => '1 rue Exemple', 'postcode' => '50380', 'town' => 'BOISJOLI',
        'phone' => '09 70 00 00 00', 'mobile' => '06 00 00 00 00', 'extra_field' => 'R-7Q2K',
    ]);
    $this->article = Article::factory()->create(['evoliz_article_id' => 7000240, 'reference' => 'ASTRA MT POM', 'unit_price_ht' => 53, 'vat_rate' => 5.5]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    $this->uuid = (string) Str::uuid();
});

/**
 * @param  array<string, mixed>  $changes
 * @return TestResponse<Response>
 */
function putCorrection(array $changes, ?string $uuid = null): TestResponse
{
    actingAs(test()->seller);

    return putJson('/api/orders/'.($uuid ?? test()->uuid), orderPayload(test()->client, [[test()->article, 2]], test()->paytype, ['client_changes' => $changes]));
}

/**
 * @param  array<string, mixed>  $changes
 */
function correctedOrder(array $changes, string $status = 'approved'): Order
{
    putCorrection($changes)->assertSuccessful();
    $order = Order::query()->where('uuid', test()->uuid)->sole();
    $order->forceFill(['status' => $status, 'approved_by' => test()->admin->id, 'approved_at' => now()])->save();

    return $order->fresh();
}

/**
 * The live PATCH /clients answer (the Evoliz connector notes in Passorde Connect "Client update") with the given fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function evolizClientRow(array $overrides = []): array
{
    return array_replace_recursive([
        'clientid' => 7000160, 'code' => 'EXEMPLEAMB', 'name' => 'EXEMPLE AMBROISE', 'type' => 'Professionnel', 'vat_number' => 'NC',
        'address' => ['addr' => '1 rue Exemple', 'addr2' => null, 'addr3' => null, 'postcode' => '50380', 'town' => 'BOISJOLI', 'country' => ['label' => 'France', 'iso2' => 'FR']],
        'phone' => '09 70 00 00 00', 'mobile' => '06 00 00 00 00', 'stampdate' => '2025-03-12T02:12:02.000000Z', 'enabled' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function evolizContactRow(array $overrides = []): array
{
    return [
        'contactid' => 7000190, 'client' => ['clientid' => 7000160, 'code' => 'EXEMPLEAMB', 'name' => 'EXEMPLE AMBROISE'],
        'lastname' => 'Exemple', 'firstname' => 'Ambroise', 'email' => 'jp@example.test', 'favorite' => true, 'enabled' => true,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function blFor(Order $order): array
{
    $delivery = evolizFixture('delivery-created');
    $delivery['external_document_number'] = $order->number;
    $delivery['total'] = ['vat_exclude' => (float) $order->total_ht, 'vat' => (float) $order->total_vat, 'vat_include' => (float) $order->total_ttc];

    return $delivery;
}

/**
 * @return list<string> "METHOD /path" of every Evoliz request after login, in order
 */
function evolizCalls(): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/login'))
        ->map(fn (Request $request) => $request->method().' '.Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/companies/10001'))
        ->values()->all();
}

/**
 * @return array<string, mixed>
 */
function bodyOf(string $method, string $path): array
{
    $request = Http::recorded(fn (Request $request) => $request->method() === $method && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))->first()[0];

    return json_decode($request->body(), true);
}

/* Saving with the order */

it('stores only the fields that differ, leaves the client as it is, and shows the correction on the order', function () {
    $response = putCorrection([
        'address_line' => '2 route du Lac', 'postcode' => '50380', 'town' => 'BOISJOLI',
        'mobile' => '07 11 22 33 44', 'email' => 'JP.Nouveau@Example.test', 'contact_name' => 'Ambroise Exemple',
    ])->assertCreated();

    $change = ClientChange::query()->sole();
    expect($change)
        ->status->toBe(ClientChangeStatus::Pending)
        ->source->toBe(ClientChangeSource::Order)
        ->user_id->toBe($this->seller->id)
        ->client_id->toBe($this->client->id)
        ->and($change->diff)->toBe([
            'address_line' => ['from' => '1 rue Exemple', 'to' => '2 route du Lac'],
            'mobile' => ['from' => '06 00 00 00 00', 'to' => '07 11 22 33 44'],
            'email' => ['from' => 'jp@example.test', 'to' => 'jp.nouveau@example.test'],
        ]);
    expect($this->client->fresh())->address_line->toBe('1 rue Exemple')->mobile->toBe('06 00 00 00 00');
    expect($response->json('order.client_change'))->toMatchArray(['status' => 'pending', 'error' => null, 'changes' => $change->diff])
        ->and($response->json('order.client.mobile'))->toBe('06 00 00 00 00');
});

it('gives the same single row when the same order is saved again, and drops it when nothing differs', function () {
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    putCorrection(['phone' => '09 70 99 99 99'])->assertOk();
    expect(ClientChange::query()->count())->toBe(1);

    putCorrection(['phone' => '09 70 00 00 00'])->assertOk();
    expect(ClientChange::query()->count())->toBe(0);

    putCorrection(['town' => 'LAMBERTOU'])->assertOk();
    putJson("/api/orders/{$this->uuid}", orderPayload($this->client, [[$this->article, 2]], $this->paytype))->assertOk();
    expect(ClientChange::query()->count())->toBe(0);
});

it('logs an update when only the correction changed', function () {
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    putCorrection(['phone' => '09 70 88 88 88'])->assertOk();

    expect(ActivityLog::query()->where('action', ActivityAction::Updated)->count())->toBe(1);
});

it('validates the correction before anything reaches Evoliz', function (array $changes, string $field) {
    putCorrection($changes)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(ClientChange::query()->count())->toBe(0);
})->with([
    'postcode of 4 digits' => [['postcode' => '1538'], 'client_changes.postcode'],
    'postcode removed' => [['postcode' => ''], 'client_changes.postcode'],
    'town removed' => [['town' => ''], 'client_changes.town'],
    'phone in words' => [['phone' => 'pas un numéro'], 'client_changes.phone'],
    'landline as mobile' => [['mobile' => '09 70 00 00 00'], 'client_changes.mobile'],
    'bad email' => [['email' => 'pas-un-email'], 'client_changes.email'],
    'email removed' => [['email' => ''], 'client_changes.email'],
    'contact removed' => [['contact_name' => ' '], 'client_changes.contact_name'],
]);

it('accepts French numbers as people type them, and clearing a phone', function () {
    putCorrection(['phone' => '+33 9 70 12 34 56', 'mobile' => '07.11.22.33.44'])->assertCreated();
    putCorrection(['phone' => null])->assertOk();

    expect(ClientChange::query()->sole()->diff)->toBe(['phone' => ['from' => '09 70 00 00 00', 'to' => null]]);
});

it('refuses a correction together with a new client', function () {
    actingAs($this->seller);
    putJson("/api/orders/{$this->uuid}", orderPayload(
        ['uuid' => (string) Str::uuid(), 'company_name' => 'SARL NEUF', 'postcode' => '71200', 'town' => 'LAMBERTOU'],
        [[$this->article, 1]], $this->paytype, ['client_changes' => ['phone' => '09 70 99 99 99']],
    ))->assertUnprocessable()->assertJsonValidationErrors('client_changes');
});

it('cancels the correction when the order is deleted or rejected', function () {
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    deleteJson("/api/orders/{$this->uuid}")->assertSuccessful();
    expect(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Cancelled);

    $this->uuid = (string) Str::uuid();
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    app(ApprovalService::class)->reject($this->admin, Order::query()->where('uuid', $this->uuid)->sole(), 'Doublon');
    expect(ClientChange::query()->latest('id')->first())->status->toBe(ClientChangeStatus::Cancelled)->decided_by->toBe($this->admin->id);
});

/* Approval */

it('lets the operator approve the order without the correction', function () {
    Queue::fake();
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    actingAs($this->admin);

    $response = postJson("/api/admin/orders/{$this->uuid}/approve", ['apply_client_change' => false])->assertOk();

    expect($response->json('order.client_change.status'))->toBe('rejected')
        ->and($response->json('order.client_change.decided_by'))->toBe('Chef')
        ->and(ActivityLog::query()->where('action', ActivityAction::ClientChangeRejected)->count())->toBe(1);
    Queue::assertPushed(PushOrderJob::class);
});

it('keeps the correction pending, decided by the operator, on a plain approval', function () {
    Queue::fake();
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    actingAs($this->admin);

    postJson("/api/admin/orders/{$this->uuid}/approve")->assertOk();

    expect(ClientChange::query()->sole())->status->toBe(ClientChangeStatus::Pending)->decided_by->toBe($this->admin->id);
});

/* Push */

it('sends the correction to Evoliz before the BL and copies the answer into the client', function () {
    $order = correctedOrder([
        'address_line' => '2 route du Lac', 'postcode' => '71200', 'town' => 'LAMBERTOU',
        'phone' => null, 'mobile' => '07 11 22 33 44', 'contact_name' => 'Paul Clairval', 'email' => 'paul@example.test', 'extra_field' => 'R-7Q3M',
    ]);
    $patched = evolizClientRow(['address' => ['addr' => '2 route du Lac', 'postcode' => '71200', 'town' => 'LAMBERTOU'], 'phone' => null, 'mobile' => '07 11 22 33 44']);
    scriptEvoliz([
        'PATCH /clients/{id}' => evolizJson($patched),
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow(['favorite' => false, 'contactid' => 1]), evolizContactRow()], 'meta' => ['last_page' => 1]]),
        'PATCH /contacts-clients/{id}' => evolizJson(evolizContactRow(['lastname' => 'Paul Clairval', 'firstname' => null, 'email' => 'paul@example.test'])),
        'GET /clients/{id}' => evolizJson($patched),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    // Passorde reads the client and its contacts back after each write (contact.read, client.read).
    expect(evolizCalls())->toBe([
        'PATCH /clients/7000160',
        'GET /contacts-clients',
        'GET /contacts-clients',
        'PATCH /contacts-clients/7000190',
        'GET /clients/7000160',
        'GET /contacts-clients',
        'GET /deliveries',
        'POST /deliveries',
    ]);
    expect(bodyOf('PATCH', '/clients/7000160'))->toBe([
        'address' => ['addr' => '2 route du Lac', 'addr2' => null, 'addr3' => null, 'postcode' => '71200', 'town' => 'LAMBERTOU'],
        'phone' => null,
        'mobile' => '07 11 22 33 44',
    ]);
    expect(bodyOf('PATCH', '/contacts-clients/7000190'))->toBe(['lastname' => 'Paul Clairval', 'firstname' => null, 'email' => 'paul@example.test']);

    expect($order->fresh())->status->toBe(OrderStatus::Sent)->evoliz_warning->toBeNull();
    expect($this->client->fresh())
        ->address_line->toBe('2 route du Lac')->postcode->toBe('71200')->town->toBe('LAMBERTOU')
        ->phone->toBe('07 11 22 33 44') // Evoliz phone empty: the mobile is shown, as the sync does
        ->mobile->toBe('07 11 22 33 44')
        ->contact_name->toBe('Paul Clairval')->email->toBe('paul@example.test')
        ->extra_field->toBe('R-7Q3M');
    expect(ClientChange::query()->sole())->status->toBe(ClientChangeStatus::Applied)->applied_at->not->toBeNull();
    expect(ActivityLog::query()->where('action', ActivityAction::ClientChangeApplied)->value('meta_json'))
        ->toBe(['fields' => 'contact, adresse, code postal, ville, téléphone, mobile, e-mail, n° adhérent']);
    expect(passordeRequests(Subject::ORDER, $order->id)->pluck('kind')->all())
        ->toBe(['client.update', 'contact.read', 'contact.lookup', 'contact.update', 'client.read', 'contact.read', 'delivery.lookup', 'delivery.create']);
});

it('takes a 500 that Evoliz applied anyway as done, after reading the client back', function () {
    $order = correctedOrder(['phone' => '09 70 99 99 99']);
    scriptEvoliz([
        'PATCH /clients/{id}' => evolizJson(['message' => 'Server Error'], 500),
        'GET /clients/{id}' => evolizJson(evolizClientRow(['phone' => '09 70 99 99 99'])),
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow()], 'meta' => ['last_page' => 1]]),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(evolizCalls())->toBe(['PATCH /clients/7000160', 'GET /clients/7000160', 'GET /contacts-clients', 'GET /deliveries', 'POST /deliveries']);
    expect($this->client->fresh()->phone)->toBe('09 70 99 99 99')
        ->and(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Applied)
        ->and(passordeRequests(Subject::ORDER, $order->id)->firstWhere('kind', 'client.update'))->status->toBe('succeeded')->last_error->toBe('Retrouvé par recherche après une réponse incertaine');
});

it('sends the PATCH again when the read-back shows it was not applied', function () {
    $order = correctedOrder(['email' => 'paul@example.test']);
    scriptEvoliz([
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow()], 'meta' => ['last_page' => 1]]),
        'PATCH /contacts-clients/{id}' => Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(evolizContactRow(['email' => 'paul@example.test'])),
        'GET /contacts-clients/{id}' => evolizJson(evolizContactRow()),
        'GET /clients/{id}' => evolizJson(evolizClientRow()),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    // Passorde's lookup (an email alone needs no read by the app), the PATCH, its read-back, the PATCH again, the client read back.
    expect(evolizCalls())->toBe([
        'GET /contacts-clients', 'PATCH /contacts-clients/7000190', 'GET /contacts-clients/7000190', 'PATCH /contacts-clients/7000190',
        'GET /clients/7000160', 'GET /contacts-clients',
        'GET /deliveries', 'POST /deliveries',
    ]);
    expect($this->client->fresh()->email)->toBe('paul@example.test')
        ->and(bodyOf('PATCH', '/contacts-clients/7000190'))->toBe(['email' => 'paul@example.test']);
});

it('still sends the BL when Evoliz refuses the correction, and warns', function () {
    $order = correctedOrder(['email' => 'paul@example.test', 'extra_field' => 'R-7Q3M']);
    scriptEvoliz([
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow()], 'meta' => ['last_page' => 1]]),
        'PATCH /contacts-clients/{id}' => evolizJson(['error' => 'Bad Request Error', 'message' => ['email' => ['The email address must be a valid email address.']]], 400),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Sent)
        ->evoliz_warning->toBe('Fiche client non mise à jour dans Evoliz : HTTP 400 — email : The email address must be a valid email address.');
    expect(ClientChange::query()->sole())
        ->status->toBe(ClientChangeStatus::Failed)
        ->error->toBe('HTTP 400 — email : The email address must be a valid email address.');
    expect($this->client->fresh())->email->toBe('jp@example.test')->extra_field->toBe('R-7Q2K');
    expect(ActivityLog::query()->where('action', ActivityAction::ClientChangeFailed)->count())->toBe(1);
});

it('creates a contact when the client has none in Evoliz', function () {
    $order = correctedOrder(['contact_name' => 'Paul Clairval']);
    scriptEvoliz([
        'GET /contacts-clients' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /contacts-clients' => evolizJson(evolizContactRow(['lastname' => 'Paul Clairval', 'firstname' => null]), 201),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(bodyOf('POST', '/contacts-clients'))->toBe(['clientid' => 7000160, 'lastname' => 'Paul Clairval', 'email' => 'jp@example.test', 'favorite' => true]);
    expect($this->client->fresh()->contact_name)->toBe('Paul Clairval');
});

it('applies a N° adhérent correction without calling Evoliz for the client', function () {
    $order = correctedOrder(['extra_field' => 'R-7Q3M']);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(evolizCalls())->toBe(['GET /deliveries', 'POST /deliveries'])
        ->and($this->client->fresh()->extra_field)->toBe('R-7Q3M')
        ->and(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Applied);
});

it('does not send a correction the operator left out', function () {
    Queue::fake();
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    $order = app(ApprovalService::class)->approve($this->admin, Order::query()->where('uuid', $this->uuid)->sole(), applyClientChange: false);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    app(SyncProvider::class)->pushOrder($order);

    expect(evolizCalls())->toBe(['GET /deliveries', 'POST /deliveries'])
        ->and($this->client->fresh()->phone)->toBe('09 70 00 00 00');
});

it('fails the order in test mode before the correction is sent', function () {
    $order = correctedOrder(['phone' => '09 70 99 99 99']);
    scriptEvoliz([]);
    config()->set('passorde-connect.writes_enabled', false);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())->status->toBe(OrderStatus::Failed)->evoliz_error->toBe('Envoi Evoliz désactivé (mode test)')
        ->and(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Pending)
        ->and(evolizCalls())->toBe([]);
});

/* Retry from the client's history */

it('sends a refused correction again from the client page', function () {
    Queue::fake();
    $order = correctedOrder(['phone' => '09 70 99 99 99']);
    $order->forceFill(['status' => OrderStatus::Sent, 'evoliz_delivery_id' => 7000050])->save();
    $change = ClientChange::query()->sole();
    $change->update(['status' => ClientChangeStatus::Failed, 'error' => 'HTTP 400 — phone']);

    actingAs($this->admin);
    post("/admin/clients/{$this->client->id}/changes/{$change->id}/retry")->assertRedirect();
    Queue::assertPushed(ApplyClientChangeJob::class, fn ($job) => $job->changeId === $change->id);

    scriptEvoliz([
        'PATCH /clients/{id}' => evolizJson(evolizClientRow(['phone' => '09 70 99 99 99'])),
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow()], 'meta' => ['last_page' => 1]]),
    ]);
    app(SyncProvider::class)->retryClientChange($change);

    expect($change->fresh())->status->toBe(ClientChangeStatus::Applied)->error->toBeNull()
        ->and($this->client->fresh()->phone)->toBe('09 70 99 99 99')
        ->and(passordeRequests()->firstWhere('kind', 'client.update')->subject_id)->toBe($order->id);
});

it('only retries a failed correction of that client', function () {
    Queue::fake();
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    $change = ClientChange::query()->sole();
    actingAs($this->admin);

    post("/admin/clients/{$this->client->id}/changes/{$change->id}/retry")->assertNotFound();
    Queue::assertNotPushed(ApplyClientChangeJob::class);
});

/* History */

it('shows the history on the client page, and records an admin N° adhérent edit', function () {
    putCorrection(['phone' => '09 70 99 99 99'])->assertCreated();
    actingAs($this->admin);
    patch("/admin/clients/{$this->client->id}", ['extra_field' => 'TX-0419'])->assertRedirect();
    patch("/admin/clients/{$this->client->id}", ['extra_field' => 'TX-0419'])->assertRedirect();

    get("/admin/clients/{$this->client->id}")->assertInertia(fn ($page) => $page
        ->component('Admin/Clients/Show')
        ->where('client.mobile', '06 00 00 00 00')
        ->has('history', 2)
        ->where('history.0.source', 'admin')
        ->where('history.0.status', 'applied')
        ->where('history.0.author', 'Chef')
        ->where('history.0.changes', ['extra_field' => ['from' => 'R-7Q2K', 'to' => 'TX-0419']])
        ->where('history.1.source', 'order')
        ->where('history.1.status', 'pending')
        ->where('history.1.author', 'Ysolde Dupont')
        ->where('history.1.order.uuid', $this->uuid)
        ->where('history.1.can_retry', false));
});

it('creates the contact with the client name when an email alone corrects a client without one', function () {
    $order = correctedOrder(['email' => 'paul@example.test']);
    scriptEvoliz([
        'GET /contacts-clients' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /contacts-clients' => evolizJson(evolizContactRow(['email' => 'paul@example.test']), 201),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(bodyOf('POST', '/contacts-clients'))->toBe(['clientid' => 7000160, 'lastname' => $this->client->contact_name, 'email' => 'paul@example.test', 'favorite' => true])
        ->and(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Applied)
        ->and($order->fresh()->status)->toBe(OrderStatus::Sent);
});

it('clears the street when the correction empties it', function () {
    $order = correctedOrder(['address_line' => '']);
    $patched = evolizClientRow(['address' => ['addr' => null, 'postcode' => '50380', 'town' => 'BOISJOLI']]);
    scriptEvoliz([
        'PATCH /clients/{id}' => evolizJson($patched),
        'GET /contacts-clients' => evolizJson(['data' => [evolizContactRow()], 'meta' => ['last_page' => 1]]),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['last_page' => 1]]),
        'POST /deliveries' => evolizJson(blFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(bodyOf('PATCH', '/clients/7000160'))->toBe(['address' => ['addr' => null, 'addr2' => null, 'addr3' => null]])
        ->and(ClientChange::query()->sole()->status)->toBe(ClientChangeStatus::Applied)
        ->and($this->client->fresh()->address_line)->toBeNull();
});
