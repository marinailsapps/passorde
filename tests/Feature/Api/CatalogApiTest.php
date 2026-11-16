<?php

use App\Domain\Clients\ClientSearch;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(fn () => actingAs(User::factory()->create()));

it('searches active clients only', function () {
    Client::factory()->create(['company_name' => 'SARL FICTIF', 'town' => 'LAMBERTOU', 'extra_field' => 'NP-58321']);
    Client::factory()->create(['company_name' => 'SARL ANCIEN', 'town' => 'LAMBERTOU', 'is_active' => false]);

    getJson('/api/clients/search?q=lambertou')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company_name', 'SARL FICTIF')
        ->assertJsonPath('data.0.extra_field', 'NP-58321');
    getJson('/api/clients/search?q=m')->assertJsonCount(0, 'data');
});

it('searches active articles with stock and reservations', function () {
    $above = Article::factory()->create(['reference' => 'ASTRA MT POM', 'stock_qty' => 7, 'unit_price_ht' => 53, 'vat_rate' => 5.5]);
    Article::factory()->create(['reference' => 'ASTRA OLD POM', 'is_active' => false]);
    Order::factory()->withLines([[$above, 2], [$above, 1, true]])->create();

    getJson('/api/articles/search?q=astra')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', [
            'id' => $above->id,
            'reference' => 'ASTRA MT POM',
            'designation' => $above->designation,
            'unit_price_ht' => '53.00',
            'vat_rate' => '5.50',
            'is_stocked' => true,
            'stock_qty' => '7.00',
            'reserved_qty' => '3.00',
        ]);
});

it('returns the last 3 orders of a client from any user, with their lines', function () {
    $client = Client::factory()->create();
    $article = Article::factory()->create();
    $retired = Article::factory()->create();
    foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $date) {
        Order::factory()->for($client)->withLines([[$article, 5]])->create(['created_at' => $date]);
    }
    $latest = Order::factory()->for($client)->status(OrderStatus::Sent)->withLines([[$article, 10], [$article, 1, true], [$retired, 2]])->create(['created_at' => '2026-09-01']);
    $retired->update(['is_active' => false]);
    Order::factory()->withLines([[$article, 1]])->create(); // another client

    getJson("/api/clients/{$client->id}/recent-orders")->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.uuid', $latest->uuid)
        ->assertJsonCount(3, 'data.0.lines')
        ->assertJsonPath('data.0.lines.1.is_free', true)
        ->assertJsonPath('data.0.lines.2.available', false)
        ->assertJsonPath('data.2.created_at', fn (string $date) => str_starts_with($date, '2026-07-01'));
});

it('lists enabled paytypes', function () {
    Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);
    Paytype::create(['evoliz_paytype_id' => 1, 'label' => 'PayPal', 'is_enabled' => false]);

    getJson('/api/paytypes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'Virement');
});

it('suggests a possible duplicate before creating a client', function () {
    $existing = Client::factory()->create(['company_name' => 'EURL DES ESSAIS', 'postcode' => '24200']);

    getJson('/api/clients/possible-duplicate?company_name=EURL DES ESSAI&postcode=24200')
        ->assertOk()->assertJsonPath('possible_duplicate.id', $existing->id);
    getJson('/api/clients/possible-duplicate?company_name=SARL TOUT AUTRE&postcode=24200')
        ->assertJsonPath('possible_duplicate', null);
});

it('caps search terms at 100 characters and 6 words', function () {
    getJson('/api/clients/search?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
    getJson('/api/articles/search?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
    getJson('/api/clients/search?q='.str_repeat('a', 100))->assertOk();

    expect(ClientSearch::queryWords('un deux trois quatre cinq six sept huit'))
        ->toBe(['un', 'deux', 'trois', 'quatre', 'cinq', 'six']);
});

it('ranks clients by best match and forgives one typo', function () {
    $eurl = Client::factory()->create(['company_name' => 'EURL BARCON', 'town' => 'CHATEAUFICTIF']);
    $sarl = Client::factory()->create(['company_name' => 'SARL BARCON ET FILS', 'town' => 'BOISJOLI']);
    $barconges = Client::factory()->create(['company_name' => 'BARCONGES ANATOLE', 'town' => 'MONTFICTIF']);
    Client::factory()->create(['company_name' => 'SARL AUTRE', 'town' => 'MONTFICTIF']);

    getJson('/api/clients/search?q=barcon')->assertOk()
        ->assertJsonPath('data.*.id', [$eurl->id, $sarl->id, $barconges->id]);
    getJson('/api/clients/search?q=baron')->assertOk()
        ->assertJsonPath('data.*.id', [$barconges->id, $eurl->id, $sarl->id]);
    getJson('/api/clients/search?q=barcon boisjoli')->assertOk()
        ->assertJsonPath('data.*.id', [$sarl->id]);
});

it('ranks articles by best match and forgives one typo', function () {
    $mt = Article::factory()->create(['reference' => 'ASTRA POM MT', 'designation' => 'ASTRA MOTTE POMMIER / PASSEPORT : 8B8796']);
    $pom = Article::factory()->create(['reference' => 'ASTRA POM', 'designation' => 'ASTRA POMMIER / PASSEPORT : 8B8791']);
    Article::factory()->create(['reference' => 'BRAVO TOM', 'designation' => 'BRAVO TOM POMMIER']);

    getJson('/api/articles/search?q=asra')->assertOk()->assertJsonPath('data.*.id', [$pom->id, $mt->id]);
    getJson('/api/articles/search?q=astra mt')->assertOk()->assertJsonPath('data.*.id', [$mt->id]);
    getJson('/api/articles/search?q=8b8796')->assertOk()->assertJsonPath('data.0.id', $mt->id);
});

it('limits the API to 120 requests a minute per user', function () {
    foreach (range(1, 120) as $request) {
        getJson('/api/paytypes')->assertOk();
    }

    getJson('/api/paytypes')->assertTooManyRequests();
});
