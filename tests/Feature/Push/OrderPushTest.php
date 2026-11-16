<?php

use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderService;
use App\Enums\ActivityAction;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\EvolizDrafts;
use App\Integrations\Sync\Push\Subject;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizJob;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    Sleep::fake();
    travelTo('2026-10-01 08:00:00'); // 10:00 in Paris

    $this->author = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->admin = User::factory()->admin()->create(['name' => 'Chef']);
    $this->client = Client::factory()->create([
        'evoliz_client_id' => 7000160, 'evoliz_code' => 'EXEMPLEAMB', 'company_name' => 'EXEMPLE AMBROISE', 'postcode' => '58380', 'town' => 'BOISJOLI',
    ]);
    $this->above = Article::factory()->create([
        'evoliz_article_id' => 7000240, 'reference' => 'ASTRA MT POM', 'unit_price_ht' => 53, 'vat_rate' => 5.5,
        'designation' => 'ASTRA MOTTE POMMIER / PG : M9 / CAL : 12-14 / LOT : A26-0412',
        'designation_raw' => 'ASTRA MOTTE POMMIER&nbsp;&nbsp;/ PG :&nbsp;M9 / CAL : 12-14 / LOT : A26-0412',
    ]);
    $this->major = Article::factory()->create([
        'evoliz_article_id' => 7000100, 'reference' => 'BRAVO TOM POMMIER', 'unit_price_ht' => 32, 'vat_rate' => 5.5,
        'designation' => 'BRAVO TOM POMMIER /CAL : 6-8 / PG : M9 / LOT : A26-0517',
    ]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
});

/**
 * The test order: ASTRA MT POM × 2 at catalogue price + 1 free unit, BRAVO TOM × 1 at 32.00.
 */
function gateOrder(?Client $client = null, string $status = 'approved'): Order
{
    $result = app(OrderService::class)->save(test()->author, (string) Str::uuid(), [
        'client_id' => ($client ?? test()->client)->id,
        'lines' => [
            ['article_id' => test()->above->id, 'quantity' => 2, 'unit_price_ht' => 53, 'is_free' => false, 'position' => 0],
            ['article_id' => test()->above->id, 'quantity' => 1, 'unit_price_ht' => 0, 'is_free' => true, 'position' => 1],
            ['article_id' => test()->major->id, 'quantity' => 1, 'unit_price_ht' => 32, 'is_free' => false, 'position' => 2],
        ],
        'paytype_id' => test()->paytype->id,
        'comment' => 'TEST APP – à supprimer',
    ]);

    $order = $result['order'];
    $order->forceFill(['status' => $status, 'approved_by' => test()->admin->id, 'approved_at' => now()])->save();

    return $order->fresh();
}

/**
 * The recorded POST /deliveries 201 body, adapted to an order: its number and
 * totals (138.00 HT, 7.59 VAT, 145.59 TTC unless overridden).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deliveryFor(Order $order, array $overrides = []): array
{
    $delivery = evolizFixture('delivery-created');
    $delivery['external_document_number'] = $order->number;
    $delivery['total']['vat_exclude'] = (float) $order->total_ht;
    $delivery['total']['vat'] = (float) $order->total_vat;
    $delivery['total']['vat_include'] = (float) $order->total_ttc;

    return array_replace_recursive($delivery, $overrides);
}

/**
 * @return array<string, mixed>
 */
function emptyList(): array
{
    return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]];
}

/**
 * @return list<Request>
 */
function sentTo(string $method, string $path): array
{
    return Http::recorded(fn (Request $request) => $request->method() === $method
        && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))
        ->map(fn ($pair) => $pair[0])->values()->all();
}

it('pushes an order of an existing client: lookup, create, sent', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->evoliz_delivery_id)->toBe(7000050)
        ->and($order->evoliz_delivery_number)->toBe('BL-20269001051')
        ->and($order->sent_at)->not->toBeNull()
        ->and($order->evoliz_warning)->toBeNull()
        ->and($order->evoliz_error)->toBeNull();

    expect(passordeRequests(Subject::ORDER, $order->id)->map(fn (stdClass $row) => [$row->kind, $row->status, $row->http_status])->all())->toBe([
        ['delivery.lookup', 'succeeded', 200],
        ['delivery.create', 'succeeded', 201],
    ]);
    expect(ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::Sent)->sole()->meta_json)
        ->toEqual(['delivery_number' => 'BL-20269001051', 'delivery_id' => 7000050]);

    $lookup = sentTo('GET', '/deliveries')[0];
    expect((int) $lookup['clientid'])->toBe(7000160)
        // Passorde reads a year back and a month ahead of the document date.
        ->and($lookup['period'])->toBe('custom')
        ->and($lookup['date_min'])->toBe('2025-09-30')
        ->and($lookup['date_max'])->toBe('2026-11-01')
        ->and((int) $lookup['per_page'])->toBe(100);
});

it('fails the order in test mode after the lookup, without creating anything', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);
    config()->set('passorde-connect.writes_enabled', false);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Failed)
        ->evoliz_error->toBe('Envoi Evoliz désactivé (mode test)')
        ->evoliz_delivery_id->toBeNull();
    expect(sentTo('POST', '/deliveries'))->toBe([])
        ->and(passordeRequests(Subject::ORDER, $order->id)->map(fn (stdClass $row) => [$row->kind, $row->status, $row->attempts])->all())->toBe([
            ['delivery.lookup', 'succeeded', 1],
            ['delivery.create', 'failed', 0],
        ]);
});

it('never creates a client in test mode', function () {
    $client = Client::factory()->fromApp()->create(['company_name' => 'SARL DU VAL', 'postcode' => '27200', 'town' => 'LAMBERTOU']);
    $order = gateOrder($client);
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson([...evolizFixture('client-created'), 'clientid' => 10500003], 201),
    ]);
    config()->set('passorde-connect.writes_enabled', false);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($client->fresh()->evoliz_client_id)->toBeNull()
        ->and(sentTo('POST', '/clients'))->toBe([]);
});

it('sends exactly this delivery payload (the contract with Evoliz)', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    $body = json_decode(sentTo('POST', '/deliveries')[0]->body(), true);

    expect(array_keys($body))->toBe(['documentdate', 'clientid', 'external_document_number', 'business_process', 'term', 'comment', 'items'])
        ->and($body['documentdate'])->toBe('2026-10-01')
        ->and($body['clientid'])->toBe(7000160)
        ->and($body['external_document_number'])->toBe($order->number)
        ->and($body['business_process'])->toBe('goods')
        ->and($body['term'])->toBe(['paytermid' => 5, 'paytypeid' => 2, 'recovery_indemnity' => true])
        ->and($body['comment'])->toBe("Commande {$order->number} saisie par Ysolde Dupont\nTEST APP – à supprimer")
        ->and($body['items'])->toHaveCount(3)
        // Paid line: article, quantity and the sales person's price; no designation, no VAT.
        ->and($body['items'][0])->toBe(['type' => 'article', 'articleid' => 7000240, 'quantity' => 2, 'unit_price' => 53])
        // Free unit: same article at 0 with the designation exactly as Evoliz stores it + " / plant offert".
        ->and($body['items'][1])->toBe([
            'type' => 'article',
            'articleid' => 7000240,
            'quantity' => 1,
            'unit_price' => 0,
            'designation' => 'ASTRA MOTTE POMMIER&nbsp;&nbsp;/ PG :&nbsp;M9 / CAL : 12-14 / LOT : A26-0412 / plant offert',
        ])
        ->and($body['items'][2])->toBe(['type' => 'article', 'articleid' => 7000100, 'quantity' => 1, 'unit_price' => 32]);

    foreach ($body['items'] as $item) {
        expect($item)->not->toHaveKey('vat_rate')->not->toHaveKey('vat');
    }
});

it('adds a line comment under the designation, and sends nothing extra without one', function () {
    $order = gateOrder();
    $order->lines()->where('position', 0)->update(['note' => "Taille <L> & M\nLivrer chez Mr \"Dupont\""]);
    $order->lines()->where('position', 1)->update(['note' => 'Remplacement']);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    $items = json_decode(sentTo('POST', '/deliveries')[0]->body(), true)['items'];
    $raw = 'ASTRA MOTTE POMMIER&nbsp;&nbsp;/ PG :&nbsp;M9 / CAL : 12-14 / LOT : A26-0412';

    // Evoliz reads HTML: the comment stays text, and the article's own text goes with it.
    expect($items[0])->toBe([
        'type' => 'article',
        'articleid' => 7000240,
        'quantity' => 2,
        'unit_price' => 53,
        'designation' => $raw."<br />\nTaille &lt;L&gt; &amp; M<br />\nLivrer chez Mr \"Dupont\"",
    ])
        ->and($items[1]['designation'])->toBe($raw." / plant offert<br />\nRemplacement")
        ->and($items[2])->not->toHaveKey('designation');
});

it('falls back to the plain designation until a sync stored the raw one', function () {
    $this->above->update(['designation_raw' => null]);
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(json_decode(sentTo('POST', '/deliveries')[0]->body(), true)['items'][1]['designation'])
        ->toBe('ASTRA MOTTE POMMIER / PG : M9 / CAL : 12-14 / LOT : A26-0412 / plant offert');
});

it('sends a negotiated price that differs from the catalogue', function () {
    $order = gateOrder();
    $order->lines()->where('position', 0)->update(['unit_price_ht' => '49.50']);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect(json_decode(sentTo('POST', '/deliveries')[0]->body(), true)['items'][0]['unit_price'])->toBe(49.5);
});

it('creates a new client and its contact, then the delivery', function () {
    $client = Client::factory()->fromApp()->create([
        'uuid' => (string) Str::uuid(),
        'company_name' => 'SARL du Val-Mary',
        'contact_name' => 'Elouan Clairval',
        'email' => 'elouan@example.test',
        'address_line' => '2 place Exemple',
        'postcode' => '27200',
        'town' => 'LAMBERTOU',
        'phone' => '06 00 00 00 00',
        'created_by' => $this->author->id,
    ]);
    $order = gateOrder($client);
    $created = [...evolizFixture('client-created'), 'clientid' => 10500001, 'code' => 'SARLDUVALM'];
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson($created, 201),
        'GET /contacts-clients' => evolizJson(emptyList()),
        'POST /contacts-clients' => evolizJson(['contactid' => 610001], 201),
        'GET /clients/{id}' => evolizJson($created),
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent);
    expect($client->fresh())
        ->evoliz_client_id->toBe(10500001)
        ->evoliz_code->toBe('SARLDUVALM')
        ->source->toBe(ClientSource::App);

    expect(sentTo('GET', '/clients')[0]['code'])->toBe('SARLDUVALM');
    expect(json_decode(sentTo('POST', '/clients')[0]->body(), true))->toBe([
        'type' => 'Professionnel',
        'name' => 'SARL du Val-Mary',
        'code' => 'SARLDUVALM',
        'vat_number' => 'NC',
        'address' => ['addr' => '2 place Exemple', 'postcode' => '27200', 'town' => 'LAMBERTOU', 'iso2' => 'FR'],
        'phone' => '06 00 00 00 00',
    ]);
    expect(json_decode(sentTo('POST', '/contacts-clients')[0]->body(), true))->toBe([
        'clientid' => 10500001, 'lastname' => 'Elouan Clairval', 'email' => 'elouan@example.test', 'favorite' => true,
    ]);
    expect(json_decode(sentTo('POST', '/deliveries')[0]->body(), true)['clientid'])->toBe(10500001);
});

it('still creates the BL when Evoliz refuses the contact, and warns', function () {
    $client = Client::factory()->fromApp()->create([
        'company_name' => 'SARL DU VAL', 'postcode' => '27200', 'town' => 'LAMBERTOU', 'contact_name' => 'Elouan Clairval', 'email' => 'pas-un-email',
    ]);
    $order = gateOrder($client);
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson([...evolizFixture('client-created'), 'clientid' => 10500002, 'code' => 'SARLDUVAL'], 201),
        'GET /contacts-clients' => evolizJson(emptyList()),
        'POST /contacts-clients' => evolizJson(['error' => 'Bad Request', 'message' => ['email' => ['The email must be a valid email address.']]], 400),
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Sent)
        ->evoliz_error->toBeNull()
        ->evoliz_warning->toBe('Contact non créé dans Evoliz : HTTP 400 — email : The email must be a valid email address.');
    expect(sentTo('POST', '/contacts-clients'))->toHaveCount(1)
        ->and(sentTo('POST', '/deliveries'))->toHaveCount(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::ContactFailed)->value('meta_json'))
        ->toBe(['error' => 'HTTP 400 — email : The email must be a valid email address.']);
});

it('picks the next code when the derived code belongs to another client', function () {
    $client = Client::factory()->fromApp()->create(['company_name' => 'SARL DU VAL', 'postcode' => '27200', 'town' => 'LAMBERTOU', 'contact_name' => null, 'email' => null]);
    $order = gateOrder($client);
    $other = ['clientid' => 777, 'code' => 'SARLDUVAL', 'name' => 'SARL DU VAL', 'address' => ['postcode' => '43000']];
    scriptEvoliz([
        'GET /clients' => fn (Request $request) => Http::response($request['code'] === 'SARLDUVAL' ? ['data' => [$other]] : ['data' => []]),
        'POST /clients' => fn (Request $request) => Http::response([...evolizFixture('client-created'), 'clientid' => 888, 'code' => $request['code']], 201),
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($client->fresh()->evoliz_code)->toBe('SARLDUVAL2')
        ->and($client->fresh()->evoliz_client_id)->toBe(888)
        ->and(sentTo('POST', '/contacts-clients'))->toBe([]);
});

it('adopts a client created by an interrupted earlier push', function () {
    $client = Client::factory()->fromApp()->create(['company_name' => 'SARL DU VAL', 'postcode' => '27200', 'evoliz_code' => 'SARLDUVAL', 'contact_name' => null, 'email' => null]);
    $order = gateOrder($client);
    scriptEvoliz([
        'GET /clients' => evolizJson(['data' => [['clientid' => 999, 'code' => 'SARLDUVAL', 'name' => 'SARL DU VAL', 'address' => ['postcode' => '27200']]]]),
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($client->fresh()->evoliz_client_id)->toBe(999)
        ->and(sentTo('POST', '/clients'))->toBe([]);
});

it('fails the order and creates nothing else when Evoliz rejects the client', function () {
    $client = Client::factory()->fromApp()->create(['company_name' => 'SARL X', 'postcode' => '27200']);
    $order = gateOrder($client);
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson(evolizFixture('client-error-400'), 400),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Failed)
        ->evoliz_error->toBe('HTTP 400 — vat_number : The vat number field is required.');
    expect(sentTo('POST', '/deliveries'))->toBe([])
        ->and($client->fresh()->evoliz_client_id)->toBeNull();
});

it('treats a BL found by its number as sent, without posting', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [deliveryFor($order)], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and($order->fresh()->evoliz_delivery_number)->toBe('BL-20269001051')
        ->and(sentTo('POST', '/deliveries'))->toBe([]);
});

it('finds the BL of an earlier run that stopped before storing it, on the retry (AlreadyExists)', function () {
    $order = gateOrder();
    /** @var ArrayObject<string, list<array<string, mixed>>> $evoliz */
    $evoliz = new ArrayObject(['bls' => []]);
    scriptEvoliz([
        'GET /deliveries' => fn () => Http::response(['data' => $evoliz['bls'], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'POST /deliveries' => function () use ($evoliz, $order) {
            $evoliz['bls'] = [deliveryFor($order)];

            return Http::response(deliveryFor($order), 201);
        },
    ]);
    PushOrderJob::dispatchSync($order->id);
    expect($order->fresh()->status)->toBe(OrderStatus::Sent);

    // As if the run had died between Evoliz's answer and storing the BL, and the job had been given up.
    $order->fresh()->forceFill(['status' => OrderStatus::Failed, 'evoliz_delivery_id' => null, 'evoliz_delivery_number' => null, 'sent_at' => null, 'evoliz_error' => 'The push job stopped'])->save();
    app(ApprovalService::class)->retry($this->admin, $order->fresh());
    expect($order->fresh())
        ->status->toBe(OrderStatus::Sent)
        ->evoliz_delivery_id->toBe(7000050)
        ->evoliz_delivery_number->toBe('BL-20269001051');
    expect(sentTo('POST', '/deliveries'))->toHaveCount(1)
        ->and(passordeRequests(Subject::ORDER, $order->id)->pluck('kind')->all())->toBe(['delivery.lookup', 'delivery.create', 'delivery.lookup'])
        ->and(EvolizDelivery::query()->where('evoliz_delivery_id', 7000050)->sole()->order_id)->toBe($order->id);
});

it('ignores BLs of the client with another external number', function () {
    $order = gateOrder();
    $other = deliveryFor($order, ['external_document_number' => 'C-2026-9999', 'deliveryid' => 1]);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [$other], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->evoliz_delivery_id)->toBe(7000050)
        ->and(sentTo('POST', '/deliveries'))->toHaveCount(1);
});

it('fails with the Evoliz messages on a 400 and does not retry', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(['error' => 'Bad Request Error', 'message' => ['items.0.articleid' => ['L\'article est désactivé.']]], 400),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Failed)
        ->evoliz_error->toBe('HTTP 400 — items.0.articleid : L\'article est désactivé.');
    expect(sentTo('POST', '/deliveries'))->toHaveCount(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::Failed)->count())->toBe(1);
    Sleep::assertNeverSlept();
});

it('waits on 429 and retries, without a lookup in between (a 429 was not processed)', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => Http::sequence()
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
            ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
            ->push(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent);
    // The transport waits once by itself, the retry policy once more before its own retry.
    Sleep::assertSequence([Sleep::for(7)->seconds(), Sleep::for(7)->seconds()]);
    $create = passordeRequests(Subject::ORDER, $order->id)->where('kind', 'delivery.create')->sole();
    expect(sentTo('GET', '/deliveries'))->toHaveCount(1)
        ->and($create->attempts)->toBe(2);
});

it('never posts twice after a timeout: the BL is found by lookup', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => Http::sequence()
            ->push(emptyList())
            ->push(['data' => [deliveryFor($order)], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'POST /deliveries' => fn () => Http::failedConnection(),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and($order->fresh()->evoliz_delivery_id)->toBe(7000050)
        ->and(sentTo('POST', '/deliveries'))->toHaveCount(1);
});

it('fails after 5 timeouts, and the admin retry finds the BL without posting', function () {
    $order = gateOrder();
    $evoliz = new ArrayObject(['hasBl' => false]);
    scriptEvoliz([
        'GET /deliveries' => fn () => Http::response($evoliz['hasBl'] ? ['data' => [deliveryFor($order)], 'meta' => ['last_page' => 1]] : emptyList()),
        'POST /deliveries' => fn () => Http::failedConnection(),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->evoliz_error)->toStartWith('Evoliz ne répond pas')
        ->and(sentTo('POST', '/deliveries'))->toHaveCount(5);

    // The BL turns up in Evoliz (the last POST went through after all).
    $evoliz['hasBl'] = true;
    app(ApprovalService::class)->retry($this->admin, $order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and(sentTo('POST', '/deliveries'))->toHaveCount(5);
});

it('finishes from the stored delivery id without lookup or post', function () {
    $order = gateOrder(status: 'failed');
    $order->forceFill(['evoliz_delivery_id' => 7000050, 'evoliz_delivery_number' => 'BL-20269001051', 'evoliz_error' => 'boom'])->save();
    scriptEvoliz([
        'GET /deliveries/{id}' => evolizJson(deliveryFor($order)),
    ]);

    app(ApprovalService::class)->retry($this->admin, $order);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and(sentTo('POST', '/deliveries'))->toBe([])
        ->and(Http::recorded())->toHaveCount(2); // login + GET /deliveries/7000050
});

it('keeps the order sent but warns when the Evoliz totals differ', function () {
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order, ['total' => ['vat' => 7.60, 'vat_include' => 145.60]]), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Sent)
        ->evoliz_warning->toBe('Écart de total : TVA app 7.59 € / Evoliz 7.60 € ; TTC app 145.59 € / Evoliz 145.60 €');
    expect(ActivityLog::query()->where('action', ActivityAction::TotalsMismatch)->count())->toBe(1);
});

it('does nothing for an order that is not approved', function () {
    $order = gateOrder(status: 'sent');
    Http::fake();

    PushOrderJob::dispatchSync($order->id);

    Http::assertNothingSent();
});

it('marks the order failed when the API keys are refused', function () {
    $order = gateOrder();
    config()->set('passorde-connect.evoliz.base_url', 'https://evoliz.test');
    Http::fake(['evoliz.test/api/login' => Http::response(['error' => 'Unauthorized'], 401)]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Failed)
        ->evoliz_error->toContain('Vérifiez les clés API');
});

it('also looks at December when pushing in January, in one lookup', function () {
    travelTo('2027-01-05 09:00:00');
    $order = gateOrder();
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    $lookups = sentTo('GET', '/deliveries');
    expect($lookups)->toHaveCount(1)
        ->and($lookups[0]['period'])->toBe('custom')
        ->and($lookups[0]['date_min'])->toBe('2026-01-04')
        ->and($lookups[0]['date_max'])->toBe('2027-02-05');
});

it('sends the BL without a paytype when the payment method is "Non précisé"', function () {
    $order = gateOrder();
    $order->update(['paytype_id' => null]);

    $body = EvolizDrafts::delivery($order->fresh(), 7000160)->toRequest();

    expect($body['term'])->toBe(['paytermid' => 5, 'recovery_indemnity' => true]);
});

it('sends no second contact when the read-only evoliz_jobs records it as created', function () {
    $client = Client::factory()->fromApp()->create([
        'evoliz_client_id' => 10500001, 'company_name' => 'SARL du Val-Mary', 'contact_name' => 'Elouan Clairval', 'email' => 'elouan@example.test',
        'postcode' => '27200', 'town' => 'LAMBERTOU', 'created_by' => $this->author->id,
    ]);
    $earlier = gateOrder($client, 'sent');
    DB::table('evoliz_jobs')->insert([
        'order_id' => $earlier->id, 'kind' => 'create_contact', 'method' => 'POST', 'path' => '/contacts-clients',
        'status' => 'succeeded', 'http_status' => 201, 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = gateOrder($client);
    scriptEvoliz([
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and(sentTo('GET', '/contacts-clients'))->toBe([])
        ->and(sentTo('POST', '/contacts-clients'))->toBe([]);
});

it('never writes the read-only evoliz_jobs', function () {
    expect(fn () => EvolizJob::query()->forceCreate(['order_id' => gateOrder()->id, 'kind' => 'create_delivery', 'method' => 'POST', 'path' => '/deliveries', 'status' => 'running']))
        ->toThrow(LogicException::class);
});

it('creates a client without a street, sending the postcode and town only', function () {
    $client = Client::factory()->fromApp()->create([
        'company_name' => 'SARL du Val-Mary', 'address_line' => null, 'postcode' => '27200', 'town' => 'LAMBERTOU',
        'contact_name' => null, 'email' => null, 'phone' => null, 'created_by' => $this->author->id,
    ]);
    $order = gateOrder($client);
    $created = [...evolizFixture('client-created'), 'clientid' => 10500001, 'code' => 'SARLDUVALM'];
    scriptEvoliz([
        'GET /clients' => evolizJson(evolizFixture('clients-empty')),
        'POST /clients' => evolizJson($created, 201),
        'GET /contacts-clients' => evolizJson(emptyList()),
        'GET /deliveries' => evolizJson(emptyList()),
        'POST /deliveries' => evolizJson(deliveryFor($order), 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and(json_decode(sentTo('POST', '/clients')[0]->body(), true))->toBe([
            'type' => 'Professionnel',
            'name' => 'SARL du Val-Mary',
            'code' => 'SARLDUVALM',
            'vat_number' => 'NC',
            'address' => ['postcode' => '27200', 'town' => 'LAMBERTOU', 'iso2' => 'FR'],
        ]);
});
