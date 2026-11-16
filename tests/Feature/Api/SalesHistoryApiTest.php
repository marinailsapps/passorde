<?php

use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizUser;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ysolde Dupont']);
    $this->client = Client::factory()->create(['company_name' => 'SARL FICTIF', 'evoliz_client_id' => 7000180]);
    $this->variety = Article::factory()->create(['reference' => 'VELOURS TIGE POM', 'unit_price_ht' => 34]);
    actingAs($this->user);
});

/**
 * A mirrored BL of the client; lines are [article|null, quantity, unit price].
 *
 * @param  list<array{0: Article|null, 1: int, 2: int|float}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function mirroredBl(Client $client, string $number, string $date, array $lines, array $attributes = []): EvolizDelivery
{
    static $id = 1000;
    $ht = array_sum(array_map(fn (array $line) => $line[1] * $line[2], $lines));
    $delivery = EvolizDelivery::query()->create([
        'evoliz_delivery_id' => ++$id, 'document_number' => $number, 'client_id' => $client->id, 'evoliz_client_id' => $client->evoliz_client_id,
        'evoliz_user_id' => 50001, 'client_name' => $client->company_name, 'status_code' => 2, 'status' => 'create', 'invoiced' => false,
        'documentdate' => $date, 'total_ht' => $ht, 'total_vat' => round($ht * 0.055, 2), 'total_ttc' => round($ht * 1.055, 2), 'total_quantity' => array_sum(array_column($lines, 1)),
        ...$attributes,
    ]);
    foreach ($lines as $position => [$article, $quantity, $price]) {
        $delivery->lines()->create([
            'position' => $position, 'article_id' => $article?->id, 'reference' => $article?->reference, 'designation' => $article->designation ?? 'Ligne libre',
            'quantity' => $quantity, 'unit_price_ht' => $price, 'vat_rate' => $article ? 5.5 : null, 'total_ht' => $quantity * $price, 'is_free' => $price == 0,
        ]);
    }

    return $delivery;
}

it('merges the client\'s Evoliz BLs and the app orders not in Evoliz yet, newest first', function () {
    $sent = Order::factory()->for($this->client)->for($this->user)->status(OrderStatus::Sent)->withLines([[$this->variety, 10]])
        ->create(['created_at' => '2026-09-01 09:00', 'sent_at' => '2026-09-02 09:00', 'evoliz_delivery_id' => 5001, 'evoliz_delivery_number' => 'BL-20269001100', 'comment' => 'Livrer lundi']);
    mirroredBl($this->client, 'BL-20269001100', '2026-09-02', [[$this->variety, 10, 32], [$this->variety, 1, 0]], ['evoliz_delivery_id' => 5001, 'order_id' => $sent->id, 'status_code' => 20, 'status' => 'invoice', 'invoiced' => true]);
    mirroredBl($this->client, 'BL-20249000007', '2024-03-05', [[$this->variety, 4, 30], [null, 1, 10]]);
    mirroredBl($this->client, 'BL-OLD-DELETED', '2024-01-05', [[$this->variety, 4, 30]])->delete();
    $pending = Order::factory()->for($this->client)->withLines([[$this->variety, 3]])->create(['created_at' => '2026-10-20 08:00']);
    $rejected = Order::factory()->for($this->client)->status(OrderStatus::Rejected)->withLines([[$this->variety, 2]])->create(['created_at' => '2026-10-10 08:00']);
    $notMirrored = Order::factory()->for($this->client)->status(OrderStatus::Sent)->withLines([[$this->variety, 1]])
        ->create(['created_at' => '2026-10-15 08:00', 'sent_at' => '2026-10-15 09:00', 'evoliz_delivery_id' => 5002, 'evoliz_delivery_number' => 'BL-20269001200']);
    Order::factory()->withLines([[$this->variety, 1]])->create(); // another client

    $data = getJson("/api/clients/{$this->client->id}/history")->assertOk()->json('data');

    expect(array_map(fn (array $entry) => [$entry['date'], $entry['delivery_number'], $entry['order_number'], $entry['status'], $entry['seller']], $data))->toBe([
        ['2026-10-20', null, $pending->number, 'pending', $pending->user->name],
        ['2026-10-15', 'BL-20269001200', $notMirrored->number, 'delivered', $notMirrored->user->name],
        ['2026-10-10', null, $rejected->number, 'rejected', $rejected->user->name],
        ['2026-09-02', 'BL-20269001100', $sent->number, 'invoiced', 'Ysolde Dupont'],
        ['2024-03-05', 'BL-20249000007', null, 'delivered', 'Evoliz'],
    ]);

    // The BL is the truth for lines and totals; the app order gives author and comment.
    expect($data[3]['comment'])->toBe('Livrer lundi')
        ->and($data[3]['lines'])->toHaveCount(2)
        ->and($data[3]['lines'][1])->toMatchArray(['reference' => 'VELOURS TIGE POM', 'quantity' => '1.00', 'unit_price_ht' => '0.00', 'is_free' => true])
        ->and($data[3]['totals'])->toBe(['ht' => '320.00', 'vat' => '17.60', 'ttc' => '337.60'])
        ->and($data[4]['lines'][1])->toMatchArray(['article_id' => null, 'designation' => 'Ligne libre', 'vat_rate' => null]);
});

it('counts an exported order as delivered, dated by its export, like salesHistory.ts', function () {
    $exported = Order::factory()->for($this->client)->status(OrderStatus::Approved)->withLines([[$this->variety, 3]])->create(['created_at' => '2026-10-19 23:30']);
    $exported->forceFill(['exported_at' => '2026-10-21 23:30'])->save();
    $waiting = Order::factory()->for($this->client)->status(OrderStatus::Approved)->withLines([[$this->variety, 1]])->create(['created_at' => '2026-10-19 08:00']);

    $data = getJson("/api/clients/{$this->client->id}/history")->assertOk()->json('data');

    // 23:30 UTC is the next day in Paris.
    expect(array_map(fn (array $entry) => [$entry['date'], $entry['order_number'], $entry['status']], $data))->toBe([
        ['2026-10-22', $exported->number, 'delivered'],
        ['2026-10-19', $waiting->number, 'pending'],
    ]);
});

it('shows the mapped app user as the seller of a BL typed in Evoliz', function () {
    mirroredBl($this->client, 'BL-20249000007', '2024-03-05', [[$this->variety, 4, 30]]);
    EvolizUser::query()->create(['evoliz_user_id' => 50001, 'label' => 'PRENOM OPERATEUR', 'user_id' => User::factory()->create(['name' => 'Elouan Clairval'])->id]);

    getJson("/api/clients/{$this->client->id}/history")->assertJsonPath('data.0.seller', 'Elouan Clairval');
});

it('offers the last 3 history entries to copy, Evoliz BLs included', function () {
    $retired = Article::factory()->create(['is_active' => false]);
    mirroredBl($this->client, 'BL-1', '2024-01-05', [[$this->variety, 1, 30]]);
    mirroredBl($this->client, 'BL-2', '2024-02-05', [[$this->variety, 2, 30]]);
    $bl = mirroredBl($this->client, 'BL-3', '2024-03-05', [[$this->variety, 4, 30], [$this->variety, 1, 0], [$retired, 2, 10], [null, 1, 10]]);
    $pending = Order::factory()->for($this->client)->withLines([[$this->variety, 3]])->create(['created_at' => '2026-10-20 08:00']);

    $data = getJson("/api/clients/{$this->client->id}/recent-orders")->assertOk()->assertJsonCount(3, 'data')->json('data');

    expect($data[0])->toMatchArray(['uuid' => $pending->uuid, 'number' => $pending->number, 'status' => 'pending', 'history_status' => 'pending', 'delivery_number' => null])
        ->and($data[1])->toMatchArray(['uuid' => "bl:{$bl->id}", 'number' => 'BL-3', 'delivery_number' => 'BL-3', 'status' => 'sent', 'history_status' => 'delivered', 'author' => 'Evoliz', 'total_ttc' => '158.25'])
        ->and(Carbon::parse($data[1]['created_at'])->timezone('Europe/Paris')->toDateString())->toBe('2024-03-05')
        ->and(array_map(fn (array $line) => [$line['position'], $line['unit_price_ht'], $line['is_free'], $line['available'], $line['catalog_unit_price_ht']], $data[1]['lines']))->toBe([
            [0, '30.00', false, true, '34.00'],
            [1, '0.00', true, true, '34.00'],
            [2, '10.00', false, false, $retired->unit_price_ht],
            [3, '10.00', false, false, null],
        ])
        ->and($data[2]['number'])->toBe('BL-2');
});

it('lists every BL and its lines for the phones, deleted BLs included', function () {
    $live = mirroredBl($this->client, 'BL-1', '2024-01-05', [[$this->variety, 1, 30], [$this->variety, 1, 0]]);
    $gone = mirroredBl($this->client, 'BL-2', '2024-02-05', [[$this->variety, 2, 30]]);
    $gone->delete();

    $deliveries = getJson('/api/deliveries?updated_since=1970-01-01T00:00:00Z')->assertOk()
        ->assertJsonPath('meta.per_page', 1000)
        ->assertJsonPath('meta.next_cursor', null)
        ->json('data');

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0])->toMatchArray(['id' => $live->id, 'document_number' => 'BL-1', 'client_id' => $this->client->id, 'client_name' => 'SARL FICTIF', 'date' => '2024-01-05', 'invoiced' => false, 'seller_id' => null, 'seller' => 'Evoliz', 'total_ht' => '30.00', 'deleted_at' => null])
        ->and($deliveries[1]['deleted_at'])->not->toBeNull();

    getJson('/api/delivery-lines')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', fn (array $line) => $line['delivery_id'] === $live->id && $line['position'] === 0 && $line['quantity'] === '1.00' && $line['is_free'] === false);

    Carbon::setTestNow(now()->addHour());
    getJson('/api/deliveries?updated_since='.urlencode(now()->subMinutes(5)->toIso8601String()))->assertJsonCount(0, 'data');
});

it('lists an order exported during a lapse once, as its BL, when the BL arrives from Evoliz', function () {
    $exported = Order::factory()->for($this->client)->for($this->user)->status(OrderStatus::Approved)->withLines([[$this->variety, 3]])
        ->create(['created_at' => '2026-10-19 08:00', 'standalone_at' => '2026-10-19 09:00', 'exported_at' => '2026-10-20 09:00']);

    expect(getJson("/api/clients/{$this->client->id}/history")->json('data'))->toHaveCount(1);

    // Typed by hand in Evoliz from the export, then mirrored and linked to the order.
    mirroredBl($this->client, 'BL-20269001300', '2026-10-20', [[$this->variety, 3, 34]], ['order_id' => $exported->id]);

    $data = getJson("/api/clients/{$this->client->id}/history")->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray(['delivery_number' => 'BL-20269001300', 'order_number' => $exported->number, 'status' => 'delivered']);
});
