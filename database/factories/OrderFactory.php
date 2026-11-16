<?php

namespace Database\Factories;

use App\Domain\Orders\OrderNumberAllocator;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Orders with lines; totals are left at 0 unless a test sets them.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fn () => OrderNumberAllocator::next(),
            'uuid' => fn () => (string) Str::uuid(),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'status' => OrderStatus::Pending,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * @param  list<array{0: Article, 1: int|float, 2?: bool}>  $lines  [article, quantity, is_free]
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (Order $order) use ($lines) {
            foreach ($lines as $position => $line) {
                [$article, $quantity] = $line;
                $isFree = $line[2] ?? false;
                $price = $isFree ? '0.00' : $article->unit_price_ht;

                $order->lines()->create([
                    'position' => $position,
                    'article_id' => $article->id,
                    'designation' => $article->designation,
                    'quantity' => $quantity,
                    'unit_price_ht' => $price,
                    'vat_rate' => $article->vat_rate,
                    'is_free' => $isFree,
                    'total_ht' => number_format($quantity * (float) $price, 2, '.', ''),
                ]);
            }
        });
    }
}
