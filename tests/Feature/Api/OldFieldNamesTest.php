<?php

use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\ArticleRequest;
use App\Models\CategoryThreshold;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\putJson;

/*
| An installation that renamed API fields keeps its phones working through its profile's older names (aliases;
| App\Http\Api\ApiAliases, docs/API.md "Old names"), here the fictional tests/Fixtures/profiles/renamed: both ways,
| a phone's unsent outbox entry or an old cached page sends the old names, and every answer carries them beside
| the current ones. A new installation has no older names (NoOldNamesTest).
*/

beforeEach(function () {
    renamedInstallation();
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['company_name' => 'SARL FICTIF', 'postcode' => '61200', 'town' => 'LAMBERTOU', 'extra_field' => 'R-7Q2K']);
    $this->article = Article::factory()->create(['reference' => 'ART A', 'unit_price_ht' => 30, 'vat_rate' => 5.5]);
    $this->paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    actingAs($this->user);
});

/**
 * @return array<string, mixed>
 */
function typedClientBody(string $uuid, string $field, ?string $value): array
{
    return ['uuid' => $uuid, 'company_name' => 'EURL NOUVELLE', 'contact_name' => null, 'address_line' => null, 'postcode' => '70640',
        'town' => 'MONTFICTIF', 'phone' => null, 'email' => null, $field => $value];
}

it('takes a typed client field under its old name, and a replay under the new name changes nothing', function () {
    $uuid = (string) Str::uuid();
    $clientUuid = (string) Str::uuid();

    $old = putJson("/api/orders/{$uuid}", orderPayload(typedClientBody($clientUuid, 'member_code', 'K-1'), [[$this->article, 2]], $this->paytype))
        ->assertCreated()
        ->assertJsonPath('order.client.extra_field', 'K-1')
        ->assertJsonPath('order.client.member_code', 'K-1');

    expect(Client::query()->where('uuid', $clientUuid)->sole()->extra_field)->toBe('K-1');
    $order = Order::query()->where('uuid', $uuid)->sole();
    $before = [$order->updated_at, Client::query()->count()];

    // The same order, as the new code sends it: idempotent.
    $new = putJson("/api/orders/{$uuid}", orderPayload(typedClientBody($clientUuid, 'extra_field', 'K-1'), [[$this->article, 2]], $this->paytype))->assertOk();

    expect([$order->fresh()->updated_at, Client::query()->count()])->toEqual($before)
        ->and($new->json('order'))->toEqual($old->json('order'));
});

it('keeps the new name when a body carries both', function () {
    $uuid = (string) Str::uuid();
    $body = [...typedClientBody((string) Str::uuid(), 'extra_field', 'NEW'), 'member_code' => 'OLD'];

    putJson("/api/orders/{$uuid}", orderPayload($body, [[$this->article, 1]], $this->paytype))->assertCreated()->assertJsonPath('order.client.extra_field', 'NEW');
});

it('takes a correction of the client field under its old name, null included', function () {
    putJson('/api/orders/'.Str::uuid(), orderPayload($this->client, [[$this->article, 1]], $this->paytype, ['client_changes' => ['member_code' => 'R-7Q3M']]))
        ->assertCreated();
    expect(ClientChange::query()->latest('id')->first()->diff)->toBe(['extra_field' => ['from' => 'R-7Q2K', 'to' => 'R-7Q3M']]);

    putJson('/api/orders/'.Str::uuid(), orderPayload($this->client, [[$this->article, 1]], $this->paytype, ['client_changes' => ['member_code' => null]]))
        ->assertCreated();
    expect(ClientChange::query()->latest('id')->first()->diff)->toBe(['extra_field' => ['from' => 'R-7Q2K', 'to' => null]]);
});

it('takes the old name when a client is saved without an order', function () {
    putJson('/api/clients/'.Str::uuid(), collect(typedClientBody('', 'member_code', 'K-9'))->except('uuid')->all())
        ->assertCreated()
        ->assertJsonPath('client.extra_field', 'K-9')
        ->assertJsonPath('client.member_code', 'K-9');
});

it('answers every client object with the old name beside the new one', function () {
    $order = putJson('/api/orders/'.Str::uuid(), orderPayload($this->client, [[$this->article, 1]], $this->paytype))->json('order');

    expect($order['client'])->toHaveKey('extra_field', 'R-7Q2K')->toHaveKey('member_code', 'R-7Q2K')
        ->and(getJson('/api/clients/search?q=fictif')->json('data.0'))->toHaveKey('extra_field', 'R-7Q2K')->toHaveKey('member_code', 'R-7Q2K')
        ->and(collect((array) getJson('/api/clients?updated_since=1970-01-01T00:00:00Z')->json('data'))->firstWhere('id', $this->client->id))
        ->toHaveKey('extra_field', 'R-7Q2K')->toHaveKey('member_code', 'R-7Q2K')
        ->and(getJson("/api/orders/{$order['uuid']}")->json('order.client'))->toHaveKey('member_code', 'R-7Q2K');
});

/**
 * An article of POMMIER / nu for the category and variant aliases.
 */
function classifiedArticle(): ArticleAttribute
{
    return ArticleAttribute::query()->create(['article_id' => test()->article->id, 'category' => 'POMMIER', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Auto]);
}

it('answers the article mirror and the stock report with the old names beside the new ones', function () {
    classifiedArticle();

    expect(collect((array) getJson('/api/articles?updated_since=1970-01-01T00:00:00Z')->json('data'))->firstWhere('id', $this->article->id))
        ->toMatchArray(['category' => 'POMMIER', 'variant' => 'nu', 'family' => 'POMMIER', 'kind' => 'nu']);

    /** @var list<array{key: string, kind: string, rows: list<array<string, mixed>>, legacy_key?: string}> $list */
    $list = getJson('/api/reports/stock')->json('tabs');
    $tabs = collect($list)->keyBy('key');
    $row = collect(array_merge(...array_map(fn (array $tab) => $tab['kind'] === 'stock' ? $tab['rows'] : [], $list)))->firstWhere('article_id', $this->article->id);

    expect($row)->toMatchArray(['category' => 'POMMIER', 'variant' => 'nu', 'family' => 'POMMIER', 'kind' => 'nu'])
        ->and($tabs['broken']['legacy_key'])->toBe('casse_ancien')
        ->and($tabs['gifts']['legacy_key'])->toBe('cadeaux_ancien')
        ->and($tabs->except(['broken', 'gifts', 'material_gifts'])->pluck('legacy_key')->filter()->all())->toBe([]);
});

it('keeps GET /api/families as the same list as GET /api/categories', function () {
    expect(getJson('/api/families')->assertOk()->json())->toBe(getJson('/api/categories')->assertOk()->json())
        ->and(getJson('/api/categories')->json('data'))->not->toBe([]);
});

it('takes a new article under the old field names', function () {
    fileMode();
    $uuid = (string) Str::uuid();

    putJson("/api/article-requests/{$uuid}", ['name' => 'nouveau', 'family' => 'POMMIER', 'kind' => 'nu', 'unit_price_ht' => 10])->assertCreated();

    expect(ArticleRequest::query()->where('uuid', $uuid)->sole())->category->toBe('POMMIER')->variant->toBe('nu');
});

it('keeps the old admin addresses and field names working for a page opened before the update', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);
    $attribute = classifiedArticle();

    get('/admin/family-thresholds')->assertRedirect('/admin/category-thresholds');
    put('/admin/family-thresholds', ['thresholds' => ['POMMIER' => 7]])->assertRedirect();
    expect(CategoryThreshold::query()->where('category', 'POMMIER')->value('threshold'))->toBe(7);

    patch("/admin/article-attributes/{$attribute->id}", ['family' => 'POIRIER', 'kind' => 'motte'])->assertRedirect();
    expect($attribute->fresh())->category->toBe('POIRIER')->variant->toBe('motte');

    post('/admin/article-attributes/bulk-family', ['ids' => [$attribute->id], 'family' => 'POMMIER'])->assertRedirect();
    expect($attribute->fresh()->category)->toBe('POMMIER');

    get('/admin/article-attributes?family=POMMIER')->assertOk()->assertInertia(fn ($page) => $page->where('filters.category', 'POMMIER')->has('articles.data', 1));
});

it('answers an order\'s totals and the order list with the old quantity names beside the new ones', function () {
    $uuid = (string) Str::uuid();
    putJson("/api/orders/{$uuid}", orderPayload($this->client, [[$this->article, 3], [$this->article, 1, true]], $this->paytype))
        ->assertCreated()
        ->assertJsonPath('order.totals.quantity', '4.00')
        ->assertJsonPath('order.totals.pieces', '4.00');

    expect(getJson('/api/orders')->json('data.0'))->toHaveKey('total_quantity', '4.00')->toHaveKey('total_pieces', '4.00');
});

it('takes the admin client form of a page opened before the update, in both modes', function () {
    actingAs(User::factory()->admin()->create());

    // Evoliz mode: the one field the admin edits.
    patch("/admin/clients/{$this->client->id}", ['member_code' => 'TX-0419'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->client->fresh()->extra_field)->toBe('TX-0419')
        ->and(ClientChange::query()->latest('id')->first()->diff)->toBe(['extra_field' => ['from' => 'R-7Q2K', 'to' => 'TX-0419']]);

    // File mode: every field; the old name keeps the value instead of clearing it.
    fileMode();
    $fields = [...$this->client->fresh()->only(['evoliz_code', 'company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email']), 'is_active' => true];
    $count = ClientChange::query()->count();
    patch("/admin/clients/{$this->client->id}", [...$fields, 'member_code' => 'TX-0419'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->client->fresh()->extra_field)->toBe('TX-0419')->and(ClientChange::query()->count())->toBe($count);

    patch("/admin/clients/{$this->client->id}", [...$fields, 'member_code' => 'K-2'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->client->fresh()->extra_field)->toBe('K-2');
});

it('answers an order\'s client correction with the old field name beside the new one', function () {
    $uuid = (string) Str::uuid();
    putJson("/api/orders/{$uuid}", orderPayload($this->client, [[$this->article, 1]], $this->paytype, ['client_changes' => ['extra_field' => 'R-7Q3M', 'town' => 'GRENADIER']]))->assertCreated();

    expect(getJson("/api/orders/{$uuid}")->json('order.client_change.changes'))->toBe([
        'town' => ['from' => 'LAMBERTOU', 'to' => 'GRENADIER'],
        'extra_field' => ['from' => 'R-7Q2K', 'to' => 'R-7Q3M'],
        'member_code' => ['from' => 'R-7Q2K', 'to' => 'R-7Q3M'],
    ]);
});

it('answers an admin client form with neither field name by a validation error, not a 500', function () {
    actingAs(User::factory()->admin()->create());

    patch("/admin/clients/{$this->client->id}", ['something_else' => 'x'])->assertRedirect()->assertSessionHasErrors(['extra_field' => 'Le champ N° adhérent doit être envoyé.']);
    expect($this->client->fresh()->extra_field)->toBe('R-7Q2K')
        ->and(ClientChange::query()->count())->toBe(0);
});
