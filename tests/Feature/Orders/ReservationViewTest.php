<?php

use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Order;

it('reserves quantities of pending, approved and failed orders, free units included', function () {
    $article = Article::factory()->create();
    $other = Article::factory()->create();

    Order::factory()->withLines([[$article, 10], [$article, 1, true]])->create();
    Order::factory()->status(OrderStatus::Approved)->withLines([[$article, 4], [$other, 2]])->create();
    Order::factory()->status(OrderStatus::Failed)->withLines([[$article, 5]])->create();
    foreach ([OrderStatus::Sent, OrderStatus::Rejected] as $status) {
        Order::factory()->status($status)->withLines([[$article, 100]])->create();
    }
    Order::factory()->withLines([[$article, 50]])->create()->delete();

    $reserved = Article::query()->withReserved()->get()->pluck('reserved_qty', 'id');

    expect((float) $reserved[$article->id])->toBe(20.0)
        ->and((float) $reserved[$other->id])->toBe(2.0);
});

it('stops reserving once the order is exported', function () {
    $article = Article::factory()->create();
    Order::factory()->status(OrderStatus::Approved)->withLines([[$article, 4]])->create();
    $exported = Order::factory()->status(OrderStatus::Approved)->withLines([[$article, 100], [$article, 1, true]])->create();
    $exported->forceFill(['exported_at' => now()])->save();

    expect((float) Article::query()->withReserved()->find($article->id)->reserved_qty)->toBe(4.0);
});

it('shows zero for articles nobody ordered', function () {
    $article = Article::factory()->create();

    expect((float) Article::query()->withReserved()->find($article->id)->reserved_qty)->toBe(0.0);
});
