<?php

use App\Domain\Ops\HealthReport;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;

/**
 * @return array<string, list<string>>
 */
function digest(): array
{
    return collect(app(HealthReport::class)->sections())->mapWithKeys(fn (array $section) => [$section['title'] => $section['lines']])->all();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function approvedOrder(string $when, array $attributes = []): Order
{
    $order = Order::factory()->for(Client::factory()->imported()->create(['company_name' => 'SARL EXEMPLE']))->status(OrderStatus::Approved)->create();
    $order->forceFill(['approved_at' => $when, ...$attributes])->save();

    return $order;
}

it('lists orders approved and not exported for too long', function () {
    fileMode();
    $order = approvedOrder(now()->subDays(3)->toDateTimeString());

    $title = 'Commandes approuvées non exportées depuis plus de 2 jours (1)';
    expect(digest())->toHaveKey($title)
        ->and(digest()[$title][0])->toContain($order->number)->toContain('SARL EXEMPLE');
});

it('leaves out recent and exported orders', function () {
    fileMode();
    approvedOrder(now()->subDay()->toDateTimeString());
    approvedOrder(now()->subDays(3)->toDateTimeString(), ['exported_at' => now()]);

    expect(array_keys(digest()))->not->toContain('Commandes approuvées non exportées depuis plus de 2 jours (1)')
        ->not->toContain('Commandes approuvées non exportées depuis plus de 2 jours (2)');
});

it('says nothing about exports in Evoliz mode', function () {
    approvedOrder(now()->subDays(3)->toDateTimeString());

    expect(array_keys(digest()))->not->toContain('Commandes approuvées non exportées depuis plus de 2 jours (1)');
});

it('warns about a mode mismatch', function () {
    fileMode();
    Article::factory()->create();

    expect(digest())->toHaveKey('Mode de synchronisation');
});

it('has no mismatch line on a clean file-mode instance', function () {
    fileMode();
    Article::factory()->imported()->create();

    expect(digest())->not->toHaveKey('Mode de synchronisation');
});
