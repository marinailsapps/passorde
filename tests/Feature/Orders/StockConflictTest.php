<?php

use App\Domain\Orders\StockConflictFinder;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    Queue::fake();
    $this->above = Article::factory()->create(['reference' => 'ASTRA MT POM', 'is_stocked' => true, 'stock_qty' => 7]);
});

/**
 * @param  list<array{0: Article, 1: int|float, 2?: bool}>  $lines
 */
function orderAt(string $time, array $lines, OrderStatus $status = OrderStatus::Pending): Order
{
    travelTo($time);

    return Order::factory()->status($status)->withLines($lines)->create();
}

it('leaves exported orders out: they no longer hold stock', function () {
    $exported = orderAt('2026-10-01 08:00', [[$this->above, 7]], OrderStatus::Approved);
    $exported->forceFill(['exported_at' => '2026-10-01 09:00'])->save();
    $later = orderAt('2026-10-01 10:00', [[$this->above, 3]]);

    $conflicts = app(StockConflictFinder::class)->all();

    expect($conflicts)->not->toHaveKey($exported->id)->not->toHaveKey($later->id);
});

it('flags the order that exceeds the stock and every later one', function () {
    $first = orderAt('2026-10-01 08:00', [[$this->above, 3]]);
    $second = orderAt('2026-10-01 09:00', [[$this->above, 3]]);
    $third = orderAt('2026-10-01 10:00', [[$this->above, 3]]);
    $fourth = orderAt('2026-10-01 11:00', [[$this->above, 1]]);

    $conflicts = app(StockConflictFinder::class)->all();

    expect($conflicts)->not->toHaveKey($first->id)->not->toHaveKey($second->id)
        ->and($conflicts[$third->id])->toBe([[
            'article_id' => $this->above->id,
            'reference' => 'ASTRA MT POM',
            'requested' => '3.00',
            'available_before' => '1.00',
            'shortfall' => '2.00',
            'stock_qty' => '7.00',
            'reserved_before' => '6.00',
            'earlier_orders' => [$first->number, $second->number],
        ]])
        ->and($conflicts[$fourth->id][0])->toMatchArray(['requested' => '1.00', 'available_before' => '-2.00', 'shortfall' => '3.00']);
});

it('counts free units', function () {
    $first = orderAt('2026-10-01 08:00', [[$this->above, 5], [$this->above, 1, true]]);
    $second = orderAt('2026-10-01 09:00', [[$this->above, 1], [$this->above, 1, true]]);

    $conflicts = app(StockConflictFinder::class)->all();

    expect($conflicts)->not->toHaveKey($first->id)
        ->and($conflicts[$second->id][0])->toMatchArray(['requested' => '2.00', 'shortfall' => '1.00']);
});

it('ignores articles without stock management', function () {
    $sweat = Article::factory()->create(['reference' => 'TABLIER', 'is_stocked' => false, 'stock_qty' => null]);
    orderAt('2026-10-01 08:00', [[$sweat, 50]]);

    expect(app(StockConflictFinder::class)->all())->toBe([]);
});

it('counts failed pushes but not sent, rejected or deleted orders', function () {
    orderAt('2026-10-01 07:00', [[$this->above, 5]], OrderStatus::Sent);
    orderAt('2026-10-01 07:30', [[$this->above, 5]], OrderStatus::Rejected);
    orderAt('2026-10-01 07:45', [[$this->above, 5]])->delete();
    $failed = orderAt('2026-10-01 08:00', [[$this->above, 4]], OrderStatus::Failed);
    $approved = orderAt('2026-10-01 08:30', [[$this->above, 2]], OrderStatus::Approved);
    $pending = orderAt('2026-10-01 09:00', [[$this->above, 2]]);

    $conflicts = app(StockConflictFinder::class)->all();

    expect(array_keys($conflicts))->toBe([$pending->id])
        ->and($conflicts[$pending->id][0]['earlier_orders'])->toBe([$failed->number, $approved->number]);
});

it('orders by creation time, not by number', function () {
    $early = orderAt('2026-10-01 10:00', [[$this->above, 6]]);
    $late = orderAt('2026-10-01 11:00', [[$this->above, 6]]);
    // The later-numbered order was created first (for example a phone that was offline).
    $late->forceFill(['created_at' => '2026-10-01 07:00:00'])->save();

    $conflicts = app(StockConflictFinder::class)->all();

    expect($conflicts)->toHaveKey($early->id)->not->toHaveKey($late->id)
        ->and($conflicts[$early->id][0]['earlier_orders'])->toBe([$late->number]);
});

it('takes a negative stock as already short', function () {
    $major = Article::factory()->create(['reference' => 'BRAVO TOM POMMIER', 'is_stocked' => true, 'stock_qty' => -12]);
    $order = orderAt('2026-10-01 08:00', [[$major, 1]]);

    expect(app(StockConflictFinder::class)->forOrder($order->id)[0])
        ->toMatchArray(['available_before' => '-12.00', 'shortfall' => '13.00']);
});

it('shows conflicts, a filter and a count to admins only', function () {
    $admin = User::factory()->admin()->create();
    $ok = orderAt('2026-10-01 08:00', [[$this->above, 5]]);
    $short = orderAt('2026-10-01 09:00', [[$this->above, 3]]);
    travelTo('2026-10-01 12:00');
    actingAs($admin);

    $list = getJson('/api/admin/orders?status=pending')->assertOk();
    $rows = jsonRows($list)->keyBy('uuid');
    expect($rows[$ok->uuid]['stock_conflicts'])->toBe([])
        ->and($rows[$short->uuid]['stock_conflicts'][0])->toMatchArray(['reference' => 'ASTRA MT POM', 'requested' => '3.00', 'available_before' => '2.00', 'shortfall' => '1.00'])
        ->and($list->json('counts.conflict'))->toBe(1);

    getJson('/api/admin/orders?status=conflict')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $short->uuid);

    getJson("/api/orders/{$short->uuid}")->assertJsonPath('order.stock_conflicts.0.earlier_orders', [$ok->number]);

    actingAs($short->user);
    getJson("/api/orders/{$short->uuid}")->assertJsonPath('order.stock_conflicts', null);
});
