<?php

use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Database\Seeders\PaytypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

use function Pest\Laravel\seed;

it('holds articles, clients and paytypes with no Evoliz id', function () {
    $articles = Article::factory()->imported()->count(2)->create();
    $clients = Client::factory()->imported()->count(2)->create();
    Paytype::query()->create(['label' => 'Virement', 'is_enabled' => true]);
    Paytype::query()->create(['label' => 'Chèque', 'is_enabled' => true]);

    expect($articles->pluck('evoliz_article_id')->filter())->toBeEmpty()
        ->and($clients->pluck('evoliz_client_id')->filter())->toBeEmpty()
        ->and(Paytype::query()->whereNull('evoliz_paytype_id')->count())->toBe(2);
});

it('keeps a reference unique only among articles with no Evoliz id', function () {
    Article::factory()->imported()->create(['reference' => 'VISION POM']);

    // Evoliz may hold duplicates, next to each other and next to an imported row.
    Article::factory()->count(2)->create(['reference' => 'VISION POM']);

    expect(fn () => Article::factory()->imported()->create(['reference' => 'VISION POM']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps a client code unique only among imported clients', function () {
    Client::factory()->imported()->create(['evoliz_code' => 'C00001']);
    Client::factory()->count(2)->create(['evoliz_code' => 'C00001']);
    Client::factory()->fromApp()->count(2)->create();

    expect(fn () => Client::factory()->imported()->create(['evoliz_code' => 'C00001']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('leaves typed clients alone: a push keeps the code before the Evoliz id exists', function () {
    // Two typed clients whose names give the same code, before either has an Evoliz id.
    $clients = Client::factory()->fromApp()->count(2)->create(['evoliz_code' => 'SARLDUVAL']);

    expect($clients->pluck('evoliz_client_id')->filter())->toBeEmpty()
        ->and(Client::query()->where('evoliz_code', 'SARLDUVAL')->count())->toBe(2);
});

it('ends the reservation when the order is exported', function (OrderStatus $status, string $expected) {
    $article = Article::factory()->create();
    $order = Order::factory()->create(['status' => $status]);
    $order->lines()->create(['position' => 0, 'article_id' => $article->id, 'designation' => 'X', 'quantity' => 4, 'unit_price_ht' => 1, 'vat_rate' => 5.5, 'is_free' => false, 'total_ht' => 4]);

    $reserved = fn () => number_format((float) Article::query()->withReserved()->find($article->id)->getAttribute('reserved_qty'), 2);
    expect($reserved())->toBe($expected);

    $order->forceFill(['exported_at' => now(), 'exported_by' => User::factory()->create()->id])->save();
    expect($reserved())->toBe('0.00');
})->with([
    'pending' => [OrderStatus::Pending, '4.00'],
    'approved' => [OrderStatus::Approved, '4.00'],
    'failed' => [OrderStatus::Failed, '4.00'],
    'sent' => [OrderStatus::Sent, '0.00'],
]);

it('calls delivered an order sent or exported, dated by whichever happened', function () {
    $sent = Order::factory()->create(['status' => OrderStatus::Sent]);
    $sent->forceFill(['sent_at' => '2026-11-01 10:00:00'])->save();
    $exported = Order::factory()->create(['status' => OrderStatus::Approved]);
    $exported->forceFill(['exported_at' => '2026-11-02 10:00:00'])->save();
    Order::factory()->create(['status' => OrderStatus::Approved]);
    Order::factory()->create(['status' => OrderStatus::Pending]);

    $rows = Order::query()->delivered()->selectRaw('id, '.Order::DELIVERED_AT_SQL.' as delivered_at')->orderBy('id')->get();

    expect($rows->pluck('id')->all())->toBe([$sent->id, $exported->id])
        ->and(substr((string) $rows[0]->getAttribute('delivered_at'), 0, 10))->toBe('2026-11-01')
        ->and(substr((string) $rows[1]->getAttribute('delivered_at'), 0, 10))->toBe('2026-11-02');
});

it('seeds the same payment methods with no Evoliz id, once', function () {
    fileMode();

    seed(PaytypeSeeder::class);
    seed(PaytypeSeeder::class);

    expect(Paytype::query()->count())->toBe(13)
        ->and(Paytype::query()->whereNotNull('evoliz_paytype_id')->exists())->toBeFalse()
        ->and(Paytype::query()->where('is_enabled', true)->orderBy('label')->pluck('label')->all())
        ->toBe(['Carte bancaire', 'Chèque', 'Espèces', 'Prélèvement', 'Virement']);
});
