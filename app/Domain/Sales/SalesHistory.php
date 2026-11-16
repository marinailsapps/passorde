<?php

namespace App\Domain\Sales;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizDeliveryLine;
use App\Models\Order;
use App\Models\OrderLine;

/**
 * A client's sales history (docs/API.md "Sales history"): the Evoliz delivery
 * notes, which are the truth once a BL exists, plus the app orders not in
 * Evoliz yet (pending, approved, failed: "en attente"), the rejected ones,
 * and sent ones whose BL the mirror has not caught yet. Newest first. An exported
 * order counts as delivered, dated by its export, until a mirrored BL is linked
 * to it (order_id): then only the BL is listed, never both.
 * resources/js/lib/salesHistory.ts builds the same entries on the phone (by order_uuid).
 */
final class SalesHistory
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const INVOICED = 'invoiced';

    public const REJECTED = 'rejected';

    /**
     * @return list<array<string, mixed>>
     */
    public function forClient(Client $client, ?int $limit = null): array
    {
        $deliveries = DeliveryPresenter::withRelations(EvolizDelivery::query())
            ->where('client_id', $client->id)
            ->with('lines')
            ->get();

        $mirrored = $deliveries->pluck('order_id')->filter()->all();
        $mirroredBls = $deliveries->pluck('evoliz_delivery_id')->all();

        // An order whose BL the mirror holds is that BL, whatever its status: a sent one, or one
        // exported during a lapse whose hand-made BL came in and was linked by order_id.
        $orders = Order::query()
            ->where('client_id', $client->id)
            ->whereNotIn('id', $mirrored === [] ? [0] : $mirrored)
            ->where(fn ($q) => $q->where('status', '!=', OrderStatus::Sent)
                ->orWhereNull('evoliz_delivery_id')
                ->orWhereNotIn('evoliz_delivery_id', $mirroredBls === [] ? [0] : $mirroredBls))
            ->with(['user', 'lines.article'])
            ->get();

        $entries = [
            ...$deliveries->map(fn (EvolizDelivery $delivery) => self::fromDelivery(
                DeliveryPresenter::row($delivery),
                $delivery->lines->map(fn (EvolizDeliveryLine $line) => DeliveryPresenter::line($line))->all(),
            ))->all(),
            ...$orders->map(fn (Order $order) => self::fromOrder($order))->all(),
        ];

        usort($entries, self::compare(...));

        return $limit === null ? $entries : array_slice($entries, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $delivery  DeliveryPresenter::row()
     * @param  list<array<string, mixed>>  $lines  DeliveryPresenter::line()
     * @return array<string, mixed>
     */
    public static function fromDelivery(array $delivery, array $lines): array
    {
        usort($lines, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return [
            'key' => 'bl:'.$delivery['id'],
            'date' => $delivery['date'],
            'delivery_number' => $delivery['document_number'],
            'order_number' => $delivery['order_number'],
            'order_uuid' => $delivery['order_uuid'],
            'status' => $delivery['invoiced'] ? self::INVOICED : self::DELIVERED,
            'seller' => $delivery['seller'],
            'comment' => $delivery['comment'],
            'lines' => array_map(fn (array $line) => [
                'article_id' => $line['article_id'],
                'reference' => $line['reference'],
                'designation' => $line['designation'],
                'quantity' => $line['quantity'],
                'unit_price_ht' => $line['unit_price_ht'],
                'vat_rate' => $line['vat_rate'],
                'is_free' => $line['is_free'],
                'total_ht' => $line['total_ht'],
            ], $lines),
            'totals' => ['ht' => $delivery['total_ht'], 'vat' => $delivery['total_vat'], 'ttc' => $delivery['total_ttc']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromOrder(Order $order): array
    {
        $status = match (true) {
            $order->status === OrderStatus::Rejected => self::REJECTED,
            $order->status === OrderStatus::Sent, $order->exported_at !== null => self::DELIVERED,
            default => self::PENDING,
        };
        $date = match (true) {
            $order->status === OrderStatus::Sent && $order->sent_at !== null => $order->sent_at,
            $order->exported_at !== null => $order->exported_at,
            default => $order->created_at,
        };

        return [
            'key' => 'order:'.$order->uuid,
            'date' => $date->copy()->timezone('Europe/Paris')->toDateString(),
            'delivery_number' => $order->evoliz_delivery_number,
            'order_number' => $order->number,
            'order_uuid' => $order->uuid,
            'status' => $status,
            'seller' => $order->user->name,
            'comment' => $order->comment,
            'lines' => $order->lines->map(fn (OrderLine $line) => [
                'article_id' => $line->article_id,
                'reference' => $line->article?->reference,
                'designation' => $line->designation,
                'quantity' => $line->quantity,
                'unit_price_ht' => $line->unit_price_ht,
                'vat_rate' => $line->vat_rate,
                'is_free' => $line->is_free,
                'total_ht' => $line->total_ht,
            ])->all(),
            'totals' => ['ht' => $order->total_ht, 'vat' => $order->total_vat, 'ttc' => $order->total_ttc],
        ];
    }

    /**
     * Newest first; on the same day, the higher BL number, then the higher order number.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compare(array $a, array $b): int
    {
        return [$b['date'], (string) $b['delivery_number'], (string) $b['order_number']] <=> [$a['date'], (string) $a['delivery_number'], (string) $a['order_number']];
    }
}
