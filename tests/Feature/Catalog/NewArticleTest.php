<?php

use App\Domain\Catalog\NewArticles;
use App\Enums\AttributeSource;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\Subject;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Sleep::fake();
    $this->seller = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->admin = User::factory()->admin()->create();
    $this->uuid = (string) Str::uuid();
    $this->fields = ['name' => ' Astra  star ', 'category' => 'POMMIER', 'variant' => 'motte', 'unit_price_ht' => 52.5];
    actingAs($this->seller);
});

/**
 * @return array<string, mixed>
 */
function evolizArticle(int $id = 7001, string $reference = 'ASTRA STAR MT POM', string $designation = 'ASTRA STAR MOTTE POMMIER'): array
{
    return [
        'articleid' => $id, 'reference' => $reference, 'reference_clean' => $reference, 'designation' => $designation, 'designation_clean' => $designation,
        'unit_price_vat_exclude' => 52.5, 'vat' => 5.5, 'sale_classification' => ['id' => 500030, 'code' => 'POMMIER', 'label' => 'POMMIER'],
        'stock_management' => true, 'stocked_quantity' => 0, 'enabled' => true,
    ];
}

function emptyArticles(): Closure
{
    return evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]);
}

/**
 * @return list<Request>
 */
function evolizCallsTo(string $method, string $path): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === $method && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))
        ->values()->all();
}

function pommierInCatalogue(int $classificationId): Article
{
    $article = Article::factory()->create(['vat_rate' => 5.5, 'sale_classification_id' => $classificationId, 'is_active' => true]);
    ArticleAttribute::query()->create(['article_id' => $article->id, 'category' => 'POMMIER', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Auto]);

    return $article;
}

it('creates the variety in Evoliz at once and hands it to the order form', function () {
    pommierInCatalogue(500030);
    scriptEvoliz(['GET /articles' => emptyArticles(), 'POST /articles' => evolizJson(evolizArticle(), 201)]);

    // The contract with Evoliz.
    putJson("/api/article-requests/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('request.status', 'created')
        ->assertJsonPath('request.error', null)
        ->assertJsonPath('request.article.reference', 'ASTRA STAR MT POM')
        ->assertJsonPath('request.article.unit_price_ht', '52.50');

    expect(json_decode(evolizCallsTo('POST', '/articles')[0]->body(), true))->toBe([
        'reference' => 'ASTRA STAR MT POM',
        'nature' => 'product',
        'designation' => 'ASTRA STAR MOTTE POMMIER',
        'sale_classificationid' => 500030,
        'unit_price' => 52.5,
        'vat_rate' => 5.5,
        'ttc' => false,
        'stock_management' => true,
        'stocked_quantity' => 0,
    ]);

    $article = Article::query()->where('evoliz_article_id', 7001)->sole();
    expect($article->attribute)->category->toBe('POMMIER')
        ->variant->toBe('motte')
        ->source->toBe(AttributeSource::Manual)
        ->needs_review->toBeFalse();

    // Every request is logged with the article request.
    $request = ArticleRequest::query()->sole();
    expect(passordeRequests(Subject::ARTICLE_REQUEST, $request->id)->pluck('kind')->all())->toBe(['article.lookup', 'article.create']);

    // The form's poll, and the order accepts the article right away.
    getJson("/api/article-requests/{$this->uuid}")->assertOk()->assertJsonPath('request.article.id', $article->id);
    $client = Client::factory()->create();
    putJson('/api/orders/'.Str::uuid(), orderPayload($client, [[$article, 2]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))->assertCreated();
});

it('makes no second article when the reference is already in Evoliz, or on a replay', function () {
    scriptEvoliz(['GET /articles' => evolizJson(['data' => [evolizArticle(7002, 'ASTRA STAR MT POM')], 'meta' => ['last_page' => 1]])]);

    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated()->assertJsonPath('request.status', 'created');
    expect(Article::query()->where('evoliz_article_id', 7002)->exists())->toBeTrue()
        ->and(evolizCallsTo('POST', '/articles'))->toBeEmpty();

    $before = Http::recorded()->count();
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertOk()->assertJsonPath('request.status', 'created');
    expect(Http::recorded()->count())->toBe($before);
});

it('does not take a partial reference match for the same article', function () {
    scriptEvoliz([
        'GET /articles' => evolizJson(['data' => [evolizArticle(7003, 'ASTRA STAR MT POM 2')], 'meta' => ['last_page' => 1]]),
        'POST /articles' => evolizJson(evolizArticle(7004), 201),
    ]);

    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated()->assertJsonPath('request.status', 'created');

    expect(Article::query()->where('evoliz_article_id', 7004)->exists())->toBeTrue();
});

it('tells the form when Evoliz refuses, and "Réessayer" with the same uuid sends it again', function () {
    scriptEvoliz([
        'GET /articles' => emptyArticles(),
        'POST /articles' => Http::sequence()
            ->push(['error' => 'Invalid', 'message' => ['reference' => ['The reference is invalid.']]], 400)
            ->push(evolizArticle(), 201),
    ]);

    putJson("/api/article-requests/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('request.status', 'failed')
        ->assertJsonPath('request.article', null);
    expect(ArticleRequest::query()->sole()->error)->toContain('reference')
        ->and(Article::query()->where('evoliz_article_id', 7001)->exists())->toBeFalse();

    // A corrected name goes with the retry.
    putJson("/api/article-requests/{$this->uuid}", [...$this->fields, 'name' => 'Astra Star'])->assertOk()->assertJsonPath('request.status', 'created');
    expect(ArticleRequest::query()->count())->toBe(1);
});

it('says so in test mode, without creating anything', function () {
    scriptEvoliz(['GET /articles' => emptyArticles()]);
    config()->set('passorde-connect.writes_enabled', false);

    putJson("/api/article-requests/{$this->uuid}", $this->fields)
        ->assertCreated()
        ->assertJsonPath('request.status', 'failed')
        ->assertJsonPath('request.error', 'Envoi Evoliz désactivé (mode test)');
});

it('validates the form, and keeps a request to its author', function () {
    putJson("/api/article-requests/{$this->uuid}", ['name' => 'A', 'category' => 'NOPE', 'variant' => 'material', 'unit_price_ht' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'category', 'variant', 'unit_price_ht']);

    scriptEvoliz(['GET /articles' => emptyArticles(), 'POST /articles' => evolizJson(evolizArticle(), 201)]);
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated();

    actingAs(User::factory()->create());
    getJson("/api/article-requests/{$this->uuid}")->assertForbidden();
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertForbidden();
});

it('takes the classification from the catalogue, falling back to what the counted articles mostly use', function () {
    pommierInCatalogue(500030);
    pommierInCatalogue(500030);
    pommierInCatalogue(500030);
    pommierInCatalogue(500010);

    expect(NewArticles::classificationFor('POMMIER'))->toBe(500030);

    $grenadier = Article::factory()->create(['vat_rate' => 5.5, 'sale_classification_id' => 500010, 'is_active' => true]);
    ArticleAttribute::query()->create(['article_id' => $grenadier->id, 'category' => 'GRENADIER', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Auto]);

    expect(NewArticles::classificationFor('GRENADIER'))->toBe(500010)
        ->and(NewArticles::classificationFor('CEDRAT'))->toBe(500030);
});

it('builds the catalogue\'s kind of reference and designation', function (string $type, string $reference, string $designation) {
    $variant = $type;

    expect(NewArticles::reference('ASTRA', 'POMMIER', $variant))->toBe($reference)
        ->and(NewArticles::designation('ASTRA', 'POMMIER', $variant))->toBe($designation);
})->with([
    ['nu', 'ASTRA POM', 'ASTRA POMMIER'],
    ['motte', 'ASTRA MT POM', 'ASTRA MOTTE POMMIER'],
]);

it('warns the operator until the catalogue shows the numbers, and the sync keeps category and type', function () {
    scriptEvoliz([
        // The look-up, then the sync once the operator typed the numbers in Evoliz.
        'GET /articles' => Http::sequence()
            ->push(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]])
            ->push(['data' => [evolizArticle(designation: 'ASTRA STAR MOTTE POMMIER / PG : PAJAM2 / CAL : 6-8 / LOT : 26')], 'meta' => ['current_page' => 1, 'last_page' => 1], 'links' => []]),
        'POST /articles' => evolizJson(evolizArticle(), 201),
    ]);
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated();
    $article = Article::query()->where('evoliz_article_id', 7001)->sole();

    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", orderPayload(Client::factory()->create(), [[$article, 1]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))
        ->assertCreated()
        ->assertJsonPath('order.articles_without_numbers', []);

    actingAs($this->admin);
    getJson("/api/orders/{$orderUuid}")->assertJsonPath('order.articles_without_numbers', ['ASTRA STAR MT POM']);

    artisan('evoliz:sync-articles')->assertSuccessful();

    getJson("/api/orders/{$orderUuid}")->assertJsonPath('order.articles_without_numbers', []);
    expect($article->fresh()->attribute)->category->toBe('POMMIER')->variant->toBe('motte');
});

it('does not warn about catalogue articles that never had numbers', function () {
    $textile = Article::factory()->create(['reference' => 'TABLIER', 'designation' => 'TABLIER']);
    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", orderPayload(Client::factory()->create(), [[$textile, 1]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))->assertCreated();

    actingAs($this->admin);
    getJson("/api/orders/{$orderUuid}")->assertJsonPath('order.articles_without_numbers', []);
});

it('leaves an article created during a sync run active, and survives it appearing in a later page', function () {
    $older = Article::factory()->create(['evoliz_article_id' => 8001, 'is_active' => true, 'created_at' => now()->subDay()]);
    $during = Article::factory()->create(['evoliz_article_id' => 8002, 'is_active' => true, 'created_at' => now()->addMinute()]);
    scriptEvoliz(['GET /articles' => evolizJson(['data' => [evolizArticle(8003, 'OTHER')], 'meta' => ['current_page' => 1, 'last_page' => 1], 'links' => []])]);

    artisan('evoliz:sync-articles')->assertSuccessful();

    expect($older->fresh()->is_active)->toBeFalse()
        ->and($during->fresh()->is_active)->toBeTrue();
});

it('asks Evoliz for the designation of a free unit or commented line still without numbers, before the BL', function () {
    $client = Client::factory()->create(['evoliz_client_id' => 7000160]);
    $variety = Article::factory()->create(['evoliz_article_id' => 7001, 'reference' => 'ASTRA STAR MT POM', 'designation' => 'ASTRA STAR MOTTE POMMIER', 'designation_raw' => 'ASTRA STAR MOTTE POMMIER', 'unit_price_ht' => 50, 'vat_rate' => 5.5]);
    $numbered = Article::factory()->create(['evoliz_article_id' => 7005, 'designation' => 'OLD / LOT : 1', 'designation_raw' => 'OLD / LOT : 1', 'unit_price_ht' => 50, 'vat_rate' => 5.5]);
    $paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    $payload = orderPayload($client, [[$variety, 1], [$variety, 1, true], [$numbered, 1], [$numbered, 1, true]], $paytype);
    $uuid = (string) Str::uuid();
    putJson("/api/orders/{$uuid}", $payload)->assertCreated();
    $order = Order::query()->where('uuid', $uuid)->sole();
    $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->admin->id]);

    $withNumbers = 'ASTRA STAR MOTTE POMMIER<br />'."\n".'PG : PAJAM2 / LOT : 26';
    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'GET /articles/{id}' => evolizJson(evolizArticle(7001, designation: $withNumbers)),
        'POST /deliveries' => fn () => Http::response([
            'deliveryid' => 1, 'document_number' => 'BL-1', 'external_document_number' => $order->number, 'status' => 'create',
            'total' => ['vat_exclude' => (float) $order->total_ht, 'vat' => (float) $order->total_vat, 'vat_include' => (float) $order->total_ttc],
        ], 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    $items = json_decode(evolizCallsTo('POST', '/deliveries')[0]->body(), true)['items'];
    expect($items[1]['designation'])->toBe($withNumbers.' / plant offert')
        ->and($items[0])->not->toHaveKey('designation')
        ->and($items[3]['designation'])->toBe('OLD / LOT : 1 / plant offert')
        ->and(evolizCallsTo('GET', '/articles/7001'))->toHaveCount(1)
        ->and(evolizCallsTo('GET', '/articles/7005'))->toBeEmpty()
        ->and($variety->fresh()->designation)->toContain('LOT : 26');
});

it('gives the order\'s lines a new variety\'s numbers when the BL is sent, for its page and printout', function () {
    scriptEvoliz([
        'GET /articles' => emptyArticles(),
        'POST /articles' => evolizJson(evolizArticle(), 201),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'GET /articles/{id}' => evolizJson(evolizArticle(designation: 'ASTRA STAR MOTTE POMMIER / PG : PAJAM2 / LOT : 26')),
        'POST /deliveries' => function () {
            $order = Order::query()->sole();

            return Http::response([
                'deliveryid' => 1, 'document_number' => 'BL-1', 'external_document_number' => $order->number, 'status' => 'create',
                'total' => ['vat_exclude' => (float) $order->total_ht, 'vat' => (float) $order->total_vat, 'vat_include' => (float) $order->total_ttc],
            ], 201);
        },
    ]);
    putJson("/api/article-requests/{$this->uuid}", $this->fields)->assertCreated();
    $variety = Article::query()->where('evoliz_article_id', 7001)->sole();

    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", orderPayload(Client::factory()->create(['evoliz_client_id' => 7000160]), [[$variety, 1]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))
        ->assertCreated()
        ->assertJsonPath('order.lines.0.designation', 'ASTRA STAR MOTTE POMMIER');
    $order = Order::query()->where('uuid', $orderUuid)->sole();
    $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->admin->id]);

    PushOrderJob::dispatchSync($order->id);

    getJson("/api/orders/{$orderUuid}")
        ->assertJsonPath('order.status', 'sent')
        ->assertJsonPath('order.lines.0.designation', 'ASTRA STAR MOTTE POMMIER / PG : PAJAM2 / LOT : 26');

    // Paid line without a comment: still no designation on the BL, Evoliz writes its own.
    expect(json_decode(evolizCallsTo('POST', '/deliveries')[0]->body(), true)['items'][0])->not->toHaveKey('designation');
});

it('copies numbers the sync already brought to a new variety\'s lines, without asking Evoliz again', function () {
    $variety = Article::factory()->create(['evoliz_article_id' => 7001, 'designation' => 'VARIETE', 'designation_raw' => 'VARIETE', 'unit_price_ht' => 50, 'vat_rate' => 5.5]);
    ArticleRequest::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $this->seller->id, 'name' => 'VARIETE', 'category' => 'POMMIER', 'variant' => 'nu',
        'unit_price_ht' => 50, 'reference' => 'VARIETE POM', 'designation' => 'VARIETE POMMIER', 'status' => ArticleRequest::CREATED, 'article_id' => $variety->id,
    ]);
    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", orderPayload(Client::factory()->create(['evoliz_client_id' => 7000160]), [[$variety, 1]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))->assertCreated();
    $variety->update(['designation' => 'VARIETE / LOT : 26', 'designation_raw' => 'VARIETE / LOT : 26']);
    $order = Order::query()->where('uuid', $orderUuid)->sole();
    $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->admin->id]);

    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'POST /deliveries' => fn () => Http::response([
            'deliveryid' => 1, 'document_number' => 'BL-1', 'external_document_number' => $order->number, 'status' => 'create',
            'total' => ['vat_exclude' => (float) $order->total_ht, 'vat' => (float) $order->total_vat, 'vat_include' => (float) $order->total_ttc],
        ], 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status->value)->toBe('sent')
        ->and($order->lines()->value('designation'))->toBe('VARIETE / LOT : 26');
});

it('leaves ordinary paid lines alone: no extra call, no change to their copy', function () {
    $textile = Article::factory()->create(['evoliz_article_id' => 7006, 'reference' => 'TABLIER', 'designation' => 'TABLIER', 'unit_price_ht' => 10, 'vat_rate' => 20]);
    $orderUuid = (string) Str::uuid();
    putJson("/api/orders/{$orderUuid}", orderPayload(Client::factory()->create(['evoliz_client_id' => 7000160]), [[$textile, 1]], Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true])))->assertCreated();
    $order = Order::query()->where('uuid', $orderUuid)->sole();
    $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->admin->id]);

    scriptEvoliz([
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
        'POST /deliveries' => fn () => Http::response([
            'deliveryid' => 1, 'document_number' => 'BL-1', 'external_document_number' => $order->number, 'status' => 'create',
            'total' => ['vat_exclude' => (float) $order->total_ht, 'vat' => (float) $order->total_vat, 'vat_include' => (float) $order->total_ttc],
        ], 201),
    ]);

    PushOrderJob::dispatchSync($order->id);

    expect($order->fresh()->status->value)->toBe('sent')
        ->and(evolizCallsTo('GET', '/articles/7006'))->toBeEmpty();
});
