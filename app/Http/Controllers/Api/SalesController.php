<?php

namespace App\Http\Controllers\Api;

use App\Domain\Orders\OrderPresenter;
use App\Domain\Sales\DeliveryPresenter;
use App\Domain\Sales\SalesHistory;
use App\Http\Controllers\Api\Concerns\DeltaListing;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizDeliveryLine;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales history (docs/API.md "Sales history"): a client's timeline, the last
 * three entries for "Copier une commande précédente", and the delta listings
 * the phones mirror the whole Evoliz delivery history with.
 */
class SalesController extends Controller
{
    use DeltaListing;

    /** BL rows are small: bigger pages keep the first full load to a few dozen requests. */
    private const DELIVERY_PAGE = 1000;

    public function history(Client $client, SalesHistory $history): JsonResponse
    {
        return response()->json(['data' => $history->forClient($client)]);
    }

    /**
     * The last 3 entries of the client's history (Evoliz BLs included), in the
     * shape of the former order-only list plus `delivery_number` and `history_status`.
     */
    public function recent(Client $client, SalesHistory $history): JsonResponse
    {
        $entries = $history->forClient($client, 3);
        $articleIds = collect($entries)->flatMap(fn (array $entry) => array_column($entry['lines'], 'article_id'))->filter()->unique()->all();
        $stock = OrderPresenter::stockMap($articleIds);
        $active = Article::query()->whereIn('id', $articleIds)->pluck('is_active', 'id');
        $orders = Order::withTrashed()->whereIn('uuid', array_filter(array_column($entries, 'order_uuid')))->get()->keyBy('uuid');

        return response()->json([
            'data' => array_map(function (array $entry) use ($stock, $active, $orders) {
                $order = $entry['order_uuid'] !== null ? $orders->get($entry['order_uuid']) : null;

                return [
                    'uuid' => $entry['order_uuid'] ?? $entry['key'],
                    'number' => $entry['order_number'] ?? $entry['delivery_number'],
                    'delivery_number' => $entry['delivery_number'],
                    'status' => $order?->status->value ?? 'sent',
                    'history_status' => $entry['status'],
                    'created_at' => $order?->created_at->toIso8601String() ?? Carbon::parse($entry['date'], 'Europe/Paris')->toIso8601String(),
                    'author' => $entry['seller'],
                    'total_ttc' => $entry['totals']['ttc'],
                    'lines' => array_map(function (array $line, int $position) use ($stock, $active) {
                        $row = $line['article_id'] !== null ? ($stock[$line['article_id']] ?? null) : null;

                        return [
                            'position' => $position,
                            'article_id' => $line['article_id'],
                            'reference' => $line['reference'],
                            'designation' => $line['designation'],
                            'quantity' => $line['quantity'],
                            'unit_price_ht' => $line['unit_price_ht'],
                            'vat_rate' => $line['vat_rate'] ?? '0.00',
                            'is_free' => $line['is_free'],
                            'total_ht' => $line['total_ht'],
                            'stock' => $row === null ? null : ['is_stocked' => $row['is_stocked'], 'stock_qty' => $row['stock_qty'], 'reserved_qty' => $row['reserved_qty']],
                            'catalog_unit_price_ht' => $row['unit_price_ht'] ?? null,
                            'available' => $line['article_id'] !== null && (bool) ($active[$line['article_id']] ?? false),
                        ];
                    }, $entry['lines'], array_keys($entry['lines'])),
                ];
            }, $entries),
        ]);
    }

    /**
     * Offline mirror of every Evoliz BL, deleted ones included with `deleted_at`.
     */
    public function deliveryDelta(Request $request): JsonResponse
    {
        return $this->deltaResponse(
            $request,
            DeliveryPresenter::withRelations(EvolizDelivery::withTrashed()),
            fn (Collection $deliveries) => $deliveries->map(fn (EvolizDelivery $delivery) => DeliveryPresenter::row($delivery)),
            perPage: self::DELIVERY_PAGE,
        );
    }

    /**
     * Offline mirror of the BL lines. A BL's lines are rewritten together
     * (one updated_at), so a delta always carries all of them.
     */
    public function lineDelta(Request $request): JsonResponse
    {
        return $this->deltaResponse(
            $request,
            EvolizDeliveryLine::query(),
            fn (Collection $lines) => $lines->map(fn (EvolizDeliveryLine $line) => DeliveryPresenter::line($line)),
            perPage: self::DELIVERY_PAGE,
        );
    }
}
