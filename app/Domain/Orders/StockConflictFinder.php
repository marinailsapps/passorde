<?php

namespace App\Domain\Orders;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Orders that would take an article's stock below zero. The orders that still
 * hold stock (pending, approved, failed, not exported) are taken in creation order and
 * their quantities, free lines included, are added up per article. The first
 * order whose running total exceeds the stock, and every later order on that
 * article, is in conflict with shortfall = running total − stock. Articles
 * without stock management are ignored. Informational only: approving stays
 * possible.
 *
 * @phpstan-type StockConflict array{article_id: int, reference: string, requested: string, available_before: string, shortfall: string, stock_qty: string, reserved_before: string, earlier_orders: list<string>}
 */
final class StockConflictFinder
{
    /**
     * Every conflict, keyed by order id, each order's list sorted by reference.
     *
     * @return array<int, list<StockConflict>>
     */
    public function all(): array
    {
        $rows = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('articles', 'articles.id', '=', 'order_lines.article_id')
            ->whereIn('orders.status', array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->reservesStock())))
            ->whereNull('orders.exported_at')
            ->whereNull('orders.deleted_at')
            ->where('articles.is_stocked', true)
            ->groupBy('orders.id', 'orders.number', 'orders.created_at', 'articles.id', 'articles.reference', 'articles.stock_qty')
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->select(['orders.id as order_id', 'orders.number', 'articles.id as article_id', 'articles.reference', 'articles.stock_qty'])
            ->selectRaw('sum(order_lines.quantity) as quantity')
            ->get();

        /** @var array<int, array{total: int, orders: list<string>}> $running per article, in hundredths */
        $running = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $articleId = (int) $row->article_id;
            $stock = OrderTotals::toCents($row->stock_qty ?? 0);
            $requested = OrderTotals::toCents($row->quantity);
            $before = $running[$articleId]['total'] ?? 0;
            $earlier = $running[$articleId]['orders'] ?? [];
            $total = $before + $requested;

            if ($total > $stock) {
                $conflicts[(int) $row->order_id][] = [
                    'article_id' => $articleId,
                    'reference' => (string) $row->reference,
                    'requested' => OrderTotals::money($requested),
                    'available_before' => OrderTotals::money($stock - $before),
                    'shortfall' => OrderTotals::money($total - $stock),
                    'stock_qty' => OrderTotals::money($stock),
                    'reserved_before' => OrderTotals::money($before),
                    'earlier_orders' => $earlier,
                ];
            }

            $running[$articleId] = ['total' => $total, 'orders' => [...$earlier, (string) $row->number]];
        }

        foreach ($conflicts as &$list) {
            usort($list, fn (array $a, array $b) => strcmp($a['reference'], $b['reference']));
        }

        return $conflicts;
    }

    /**
     * @return list<StockConflict>
     */
    public function forOrder(int $orderId): array
    {
        return $this->all()[$orderId] ?? [];
    }
}
