<?php

use App\Enums\AttributeSource;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

it('requires a session', function () {
    auth()->logout();

    getJson('/api/clients?updated_since=2026-10-01T00:00:00Z')->assertUnauthorized();
    getJson('/api/articles')->assertUnauthorized();
});

it('returns the clients changed since a date, deactivated ones included', function () {
    travelTo('2026-10-01 08:00:00');
    Client::factory()->create();
    travelTo('2026-10-01 10:00:00');
    $changed = Client::factory()->create(['company_name' => 'SARL DU VAL']);
    $gone = Client::factory()->create(['is_active' => false]);
    travelTo('2026-10-01 10:05:00');

    $response = getJson('/api/clients?'.http_build_query(['updated_since' => '2026-10-01T09:00:00+00:00']))->assertOk();

    expect(jsonRows($response)->pluck('id')->all())->toBe([$changed->id, $gone->id])
        ->and($response->json('data.0'))->toMatchArray(['company_name' => 'SARL DU VAL', 'is_active' => true, 'linked' => true, 'updated_at' => '2026-10-01T10:00:00+00:00'])
        ->and($response->json('data.1.is_active'))->toBeFalse()
        ->and($response->json('meta'))->toBe(['per_page' => 200, 'next_cursor' => null, 'until' => '2026-10-01T10:05:00+00:00']);

    // Without updated_since: everything (the first load).
    expect(getJson('/api/clients')->json('data'))->toHaveCount(3);
});

it('pages 200 rows at a time over a fixed set', function () {
    travelTo('2026-10-01 08:00:00');
    Client::factory()->count(205)->create();
    travelTo('2026-10-01 09:00:00');

    $first = getJson('/api/clients?updated_since=2026-10-01T00:00:00Z')->assertOk();
    expect($first->json('data'))->toHaveCount(200)
        ->and($first->json('meta.next_cursor'))->toBeString();

    // A row created between two pages is left for the next delta, not slipped in.
    travelTo('2026-10-01 09:00:30');
    Client::factory()->create();

    $second = getJson('/api/clients?'.http_build_query([
        'updated_since' => '2026-10-01T00:00:00Z',
        'until' => $first->json('meta.until'),
        'cursor' => $first->json('meta.next_cursor'),
    ]))->assertOk();

    expect($second->json('data'))->toHaveCount(5)
        ->and($second->json('meta.next_cursor'))->toBeNull()
        ->and(jsonRows($first)->merge(jsonRows($second))->pluck('id')->unique())->toHaveCount(205);
});

it('returns changed articles with stock, and every reservation', function () {
    travelTo('2026-10-01 08:00:00');
    $quiet = Article::factory()->create(['is_stocked' => true, 'stock_qty' => 7]);
    travelTo('2026-10-01 10:00:00');
    $changed = Article::factory()->create(['reference' => 'ASTRA MT POM', 'is_stocked' => true, 'stock_qty' => 4]);
    $retired = Article::factory()->create(['is_active' => false]);
    // Reservations move with orders, not with the article row.
    Order::factory()->withLines([[$quiet, 2], [$changed, 1]])->create();
    Order::factory()->status(OrderStatus::Sent)->withLines([[$quiet, 9]])->create();

    $response = getJson('/api/articles?updated_since=2026-10-01T09:00:00Z')->assertOk();

    expect(jsonRows($response)->pluck('id')->all())->toBe([$changed->id, $retired->id])
        ->and($response->json('data.0'))->toMatchArray(['reference' => 'ASTRA MT POM', 'stock_qty' => '4.00', 'reserved_qty' => '1.00', 'is_active' => true])
        ->and($response->json('data.1.is_active'))->toBeFalse()
        ->and($response->json('reservations'))->toBe([(string) $quiet->id => '2.00', (string) $changed->id => '1.00']);
});

it('gives each article its category and type, and sends it again when they change', function () {
    travelTo('2026-10-01 10:00:00');
    $article = Article::factory()->create();
    $attribute = ArticleAttribute::query()->create(['article_id' => $article->id, 'category' => null, 'variant' => 'motte', 'needs_review' => true, 'source' => AttributeSource::Auto]);

    travelTo('2026-10-02 10:00:00');
    $attribute->update(['category' => 'POMMIER', 'needs_review' => false]);

    getJson('/api/articles?updated_since=2026-10-01T12:00:00Z')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'POMMIER')
        ->assertJsonPath('data.0.variant', 'motte');
});

it('returns the reservations as an object even when there are none', function () {
    expect(getJson('/api/articles?updated_since=2026-10-01T00:00:00Z')->getContent())->toContain('"reservations":{}');
});

it('returns the caller\'s own orders in full, deleted ones included', function () {
    $article = Article::factory()->create();
    travelTo('2026-10-01 08:00:00');
    $old = Order::factory()->for($this->user)->withLines([[$article, 1]])->create();
    travelTo('2026-10-01 10:00:00');
    $mine = Order::factory()->for($this->user)->withLines([[$article, 2]])->create();
    $deleted = Order::factory()->for($this->user)->withLines([[$article, 3]])->create();
    $deleted->delete();
    Order::factory()->withLines([[$article, 4]])->create(); // someone else's
    $old->update(['status' => OrderStatus::Approved]); // a status change moves updated_at

    $response = getJson('/api/orders?updated_since=2026-10-01T09:00:00Z')->assertOk();
    $rows = jsonRows($response)->keyBy('uuid');

    expect($rows->keys()->all())->toBe([$old->uuid, $mine->uuid, $deleted->uuid])
        ->and($rows[$old->uuid])->toMatchArray(['status' => 'approved', 'display_status' => 'approved', 'deleted_at' => null])
        ->and($rows[$mine->uuid]['lines'][0])->toMatchArray(['quantity' => '2.00'])
        ->and($rows[$mine->uuid])->toHaveKeys(['number', 'client', 'paytype', 'totals', 'can_edit', 'status_label'])
        ->and($rows[$deleted->uuid]['deleted_at'])->toBe('2026-10-01T10:00:00+00:00')
        ->and($response->json('meta.per_page'))->toBe(200);
});

it('keeps the paginated summaries when updated_since is absent', function () {
    Order::factory()->for($this->user)->create();

    getJson('/api/orders')->assertOk()
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonMissingPath('data.0.lines');
});

it('validates the dates', function () {
    getJson('/api/clients?updated_since=yesterday-ish')->assertJsonValidationErrors('updated_since');
});
