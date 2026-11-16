<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\Catalog;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sales\DeliveryPresenter;
use App\Models\EvolizDelivery;
use App\Models\EvolizDeliveryLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\EvolizState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Ventes" (docs/RUNBOOK.md "Reports: sales"): what was delivered in a period,
 * from the mirrored Evoliz delivery notes only (app orders not in Evoliz yet
 * never count). Filters: documentdate period, BL not invoiced ("Bons de
 * livraison") and/or invoiced ("Facturés"), seller. Whenever Evoliz does not run
 * (switched off, keys missing, licence lapsed) the same rows come from the app's
 * own delivered orders, sent or exported, dated by that (deliveredInput()).
 *
 * aggregate() is a pure function of plain rows, mirrored line for line by
 * resources/js/lib/salesReport.ts (the phone computes the report offline);
 * both are pinned by tests/Fixtures/sales-report-cases.json: change the three
 * together.
 *
 * Quantities ("quantity" in the answer) are those of lines whose article is known
 * and whose variant counts (input "variants", the profile's catalog.variants;
 * "dont offertes": the 0 € lines among them). In the category grouping a variant
 * with its own stock tab groups as ~material, one that does not count as ~other.
 * Cards and the groups made of whole BLs (client, month, département) take
 * HT, TVA and TTC from the BL totals; the groups that split a BL (category,
 * article, variant) sum the line HT and compute VAT per rate on it, as
 * Evoliz does on a document. A group's count is its number of BLs.
 */
final class SalesReport
{
    public const GROUPINGS = ['category', 'article', 'client', 'month', 'department', 'variant'];

    /** Groupings whose rows are whole BLs. */
    private const BY_DELIVERY = ['client', 'month', 'department'];

    /** Special group keys; their labels are translated ("sales.~none"). */
    public const NO_ARTICLE = '~none';

    public const MATERIAL = '~material';

    public const OTHER = '~other';

    public const UNCLASSIFIED = '~unclassified';

    public const UNKNOWN = '~unknown';

    /**
     * The profile's variants as aggregate() reads them (input "variants"; the phone takes them
     * from its session's catalog prop).
     *
     * @return list<array{value: string, label: string, counts: bool, own_tab: bool}>
     */
    public static function variants(): array
    {
        return array_map(fn (array $variant) => [
            'value' => $variant['value'],
            'label' => $variant['label'],
            'counts' => $variant['counts'],
            'own_tab' => $variant['group'] !== null,
        ], Catalog::variants());
    }

    /**
     * The quick periods, for today in Europe/Paris: the current ones run to today.
     *
     * @return list<array{key: string, from: string, to: string}>
     */
    public static function presets(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now('Europe/Paris'))->copy()->startOfDay();
        $lastWeek = $today->copy()->startOfWeek()->subWeek();

        $presets = [
            'this_week' => [$today->copy()->startOfWeek(), $today],
            'last_week' => [$lastWeek, $lastWeek->copy()->endOfWeek()],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'this_quarter' => [$today->copy()->firstOfQuarter(), $today],
            'this_year' => [$today->copy()->startOfYear(), $today],
        ];

        $list = [];
        foreach ($presets as $key => [$start, $end]) {
            $list[] = ['key' => $key, 'from' => $start->toDateString(), 'to' => $end->toDateString()];
        }

        return $list;
    }

    /**
     * The rows of the period from the database, ready for aggregate(); the
     * seller's name and the lines' designation and price are for the Excel
     * "Détail" sheet only.
     *
     * @param  array{from: string, to: string, delivered: bool, invoiced: bool, seller_id: int|null}  $filters
     * @return array<string, mixed>
     */
    public function input(array $filters): array
    {
        if (! self::fromEvoliz()) {
            return $this->deliveredInput($filters);
        }

        $deliveries = DeliveryPresenter::withRelations(EvolizDelivery::query())
            ->whereBetween('documentdate', [$filters['from'], $filters['to']])
            ->get();
        $ids = $deliveries->pluck('id')->all();

        $lines = EvolizDeliveryLine::query()->whereIn('delivery_id', $ids)->orderBy('delivery_id')->orderBy('position')->get();
        $articleIds = $lines->pluck('article_id')->filter()->unique()->values()->all();
        $clientIds = $deliveries->pluck('client_id')->filter()->unique()->values()->all();

        return [
            'deliveries' => $deliveries->map(function (EvolizDelivery $delivery) {
                $row = DeliveryPresenter::row($delivery);

                return [
                    'id' => $row['id'],
                    'number' => $row['document_number'],
                    'date' => $row['date'],
                    'client_id' => $row['client_id'],
                    'client_name' => $row['client_name'],
                    'invoiced' => $row['invoiced'],
                    'seller_id' => $row['seller_id'],
                    'seller' => $row['seller'],
                    'total_ht' => $row['total_ht'],
                    'total_vat' => $row['total_vat'],
                    'total_ttc' => $row['total_ttc'],
                ];
            })->all(),
            'lines' => $lines->map(fn (EvolizDeliveryLine $line) => [
                'delivery_id' => $line->delivery_id,
                'article_id' => $line->article_id,
                'reference' => $line->reference,
                'designation' => $line->designation,
                'unit_price_ht' => $line->unit_price_ht,
                'quantity' => $line->quantity,
                'total_ht' => $line->total_ht,
                'vat_rate' => $line->vat_rate,
                'is_free' => $line->is_free,
            ])->all(),
            ...self::references($articleIds, $clientIds),
            'filters' => $filters,
        ];
    }

    /**
     * Evoliz not running: each delivered order of the period (Order::DELIVERED_SQL:
     * sent to Evoliz before, or exported) is one "delivery", never invoiced, dated
     * by its sending or export. In a file-mode installation that is the exported
     * orders. BLs made in Evoliz itself are not counted until Evoliz runs again.
     *
     * @param  array{from: string, to: string, delivered: bool, invoiced: bool, seller_id: int|null}  $filters
     * @return array<string, mixed>
     */
    private function deliveredInput(array $filters): array
    {
        $orders = Order::query()->delivered()
            ->whereRaw(Order::DELIVERED_AT_SQL.' between ? and ?', [
                Carbon::parse($filters['from'], 'Europe/Paris')->startOfDay()->utc(),
                Carbon::parse($filters['to'], 'Europe/Paris')->endOfDay()->utc(),
            ])
            ->with(['client', 'user', 'lines.article'])
            ->get();

        return [
            'deliveries' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'date' => ($order->sent_at ?? $order->exported_at)?->copy()->timezone('Europe/Paris')->toDateString(),
                // For the Excel status column: exported, or sent to Evoliz before it stopped.
                'exported' => $order->exported_at !== null,
                'client_id' => $order->client_id,
                'client_name' => $order->client->company_name,
                'invoiced' => false,
                'seller_id' => $order->user_id,
                'seller' => $order->user->name,
                'total_ht' => $order->total_ht,
                'total_vat' => $order->total_vat,
                'total_ttc' => $order->total_ttc,
            ])->all(),
            'lines' => $orders->flatMap(fn (Order $order) => $order->lines->map(fn (OrderLine $line) => [
                'delivery_id' => $order->id,
                'article_id' => $line->article_id,
                'reference' => $line->article?->reference,
                'designation' => $line->designation,
                'unit_price_ht' => $line->unit_price_ht,
                'quantity' => $line->quantity,
                'total_ht' => $line->total_ht,
                'vat_rate' => $line->vat_rate,
                'is_free' => $line->is_free,
            ]))->values()->all(),
            ...self::references(
                $orders->flatMap(fn (Order $order) => $order->lines->pluck('article_id'))->filter()->unique()->values()->all(),
                $orders->pluck('client_id')->unique()->values()->all(),
            ),
            'filters' => [...$filters, 'delivered' => true],
        ];
    }

    /**
     * @param  array<int, int|null>  $articleIds
     * @param  array<int, int|null>  $clientIds
     * @return array{articles: array<string, array<string, mixed>>, clients: array<string, array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    private static function references(array $articleIds, array $clientIds): array
    {
        return [
            'variants' => self::variants(),
            'articles' => DB::table('articles')->leftJoin('article_attributes', 'article_attributes.article_id', '=', 'articles.id')
                ->whereIn('articles.id', $articleIds)
                ->get(['articles.id', 'articles.reference', 'article_attributes.category', 'article_attributes.variant'])
                ->mapWithKeys(fn (object $row) => [(string) $row->id => ['reference' => $row->reference, 'category' => $row->category, 'variant' => $row->variant]])
                ->all(),
            'clients' => DB::table('clients')->whereIn('id', $clientIds)->get(['id', 'company_name', 'postcode'])
                ->mapWithKeys(fn (object $row) => [(string) $row->id => ['name' => $row->company_name, 'postcode' => $row->postcode]])
                ->all(),
        ];
    }

    /**
     * The Evoliz delivery mirror while Evoliz runs; the app's own delivered orders
     * in every other state, so an order
     * exported during a lapse counts at once. The phone follows: syncProvider is
     * "file" in those states and its offline Ventes page is the notice.
     */
    public static function fromEvoliz(): bool
    {
        return EvolizState::active();
    }

    /**
     * Who can be picked in the seller filter: the authors of app orders that
     * became BLs and the app users mapped to Evoliz users; in file mode, the
     * authors of delivered orders.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function sellers(): array
    {
        if (! self::fromEvoliz()) {
            return DB::table('users')
                ->whereIn('id', DB::table('orders')->whereRaw(Order::DELIVERED_SQL)->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (object $user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
                ->all();
        }

        return DB::table('users')
            ->whereIn('id', DB::table('orders')->whereIn('id', DB::table('evoliz_deliveries')->whereNotNull('order_id')->select('order_id'))->select('user_id'))
            ->orWhereIn('id', DB::table('evoliz_users')->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input  deliveries, lines, articles, clients, filters (see input())
     * @return array{cards: array<string, int|string>, groups: array<string, list<array<string, mixed>>>, deliveries: list<array<string, mixed>>}
     */
    public static function aggregate(array $input): array
    {
        $filters = $input['filters'];
        $articles = $input['articles'];
        $clients = $input['clients'];
        $variants = array_column($input['variants'] ?? [], null, 'value');

        $deliveries = array_values(array_filter($input['deliveries'], fn (array $d) => $d['date'] >= $filters['from'] && $d['date'] <= $filters['to']
            && ($d['invoiced'] ? $filters['invoiced'] : $filters['delivered'])
            && ($filters['seller_id'] === null || $d['seller_id'] === $filters['seller_id'])));
        usort($deliveries, fn (array $a, array $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['number'], $a['number']) ?: $b['id'] <=> $a['id']);

        $linesOf = [];
        foreach ($input['lines'] as $line) {
            $linesOf[$line['delivery_id']][] = $line;
        }

        $cards = ['count' => 0, 'quantity' => 0, 'free_quantity' => 0, 'ht' => 0, 'vat' => 0, 'ttc' => 0];
        $groups = array_fill_keys(self::GROUPINGS, []);
        $listed = [];

        foreach ($deliveries as $delivery) {
            $client = $delivery['client_id'] !== null ? ($clients[(string) $delivery['client_id']] ?? null) : null;
            $clientLabel = $client['name'] ?? $delivery['client_name'] ?? '—';
            $ht = OrderTotals::toCents($delivery['total_ht']);
            $ttc = OrderTotals::toCents($delivery['total_ttc']);

            $cards['count']++;
            $cards['ht'] += $ht;
            $cards['vat'] += OrderTotals::toCents($delivery['total_vat']);
            $cards['ttc'] += $ttc;

            $deliveryQuantity = 0;
            $free = 0;
            foreach ($linesOf[$delivery['id']] ?? [] as $line) {
                $article = $line['article_id'] !== null ? ($articles[(string) $line['article_id']] ?? null) : null;
                [$lineQuantity, $lineFree] = self::quantities($line, $article, $variants);
                $deliveryQuantity += $lineQuantity;
                $free += $lineFree;

                foreach (['category', 'article', 'variant'] as $grouping) {
                    [$key, $label] = self::lineKey($grouping, $line, $article, $variants);
                    $row = &self::row($groups[$grouping], $key, $label);
                    self::addDelivery($row, $delivery['id']);
                    $row['quantity'] += $lineQuantity;
                    $row['free_quantity'] += $lineFree;
                    $row['ht'] += OrderTotals::toCents($line['total_ht']);
                    $rate = $line['vat_rate'] === null ? '0.00' : number_format((float) $line['vat_rate'], 2, '.', '');
                    $row['ht_by_rate'][$rate] = ($row['ht_by_rate'][$rate] ?? 0) + OrderTotals::toCents($line['total_ht']);
                    unset($row);
                }
            }
            $cards['quantity'] += $deliveryQuantity;
            $cards['free_quantity'] += $free;

            $postcode = (string) ($client['postcode'] ?? '');
            $byDelivery = [
                'client' => $delivery['client_id'] !== null ? ['id:'.$delivery['client_id'], $clientLabel] : ['name:'.$clientLabel, $clientLabel],
                'month' => [substr($delivery['date'], 0, 7), substr($delivery['date'], 0, 7)],
                'department' => preg_match('/^\d{5}$/', $postcode) === 1 ? [substr($postcode, 0, 2), substr($postcode, 0, 2)] : [self::UNKNOWN, null],
            ];
            foreach ($byDelivery as $grouping => [$key, $label]) {
                $row = &self::row($groups[$grouping], $key, $label);
                self::addDelivery($row, $delivery['id']);
                $row['quantity'] += $deliveryQuantity;
                $row['free_quantity'] += $free;
                $row['ht'] += $ht;
                $row['ttc'] += $ttc;
                unset($row);
            }

            $listed[] = [
                'id' => $delivery['id'],
                'number' => $delivery['number'],
                'date' => $delivery['date'],
                'client' => $clientLabel,
                'invoiced' => $delivery['invoiced'],
                'ht' => OrderTotals::money($ht),
                'ttc' => OrderTotals::money($ttc),
            ];
        }

        $result = [];
        foreach ($groups as $grouping => $rows) {
            $rows = array_values(array_map(fn (array $row) => self::finishRow($row, ! in_array($grouping, self::BY_DELIVERY, true)), $rows));
            usort($rows, $grouping === 'month'
                ? fn (array $a, array $b) => strcmp($a['key'], $b['key'])
                : fn (array $a, array $b) => (OrderTotals::toCents($b['ht']) <=> OrderTotals::toCents($a['ht'])) ?: strcmp($a['key'], $b['key']));
            $result[$grouping] = $rows;
        }

        return [
            'cards' => [
                'count' => $cards['count'],
                'quantity' => OrderTotals::money($cards['quantity']),
                'free_quantity' => OrderTotals::money($cards['free_quantity']),
                'ht' => OrderTotals::money($cards['ht']),
                'vat' => OrderTotals::money($cards['vat']),
                'ttc' => OrderTotals::money($cards['ttc']),
            ],
            'groups' => $result,
            'deliveries' => $listed,
        ];
    }

    /**
     * Counted and free quantities of a line, in hundredths: not for a variant listed as not counting.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $article
     * @param  array<string, array<string, mixed>>  $variants
     * @return array{0: int, 1: int}
     */
    private static function quantities(array $line, ?array $article, array $variants): array
    {
        if ($article === null || ($variants[(string) $article['variant']]['counts'] ?? true) === false) {
            return [0, 0];
        }
        $quantity = OrderTotals::toCents($line['quantity']);

        return [$quantity, $line['is_free'] ? $quantity : 0];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $article
     * @param  array<string, array<string, mixed>>  $variants
     * @return array{0: string, 1: string|null}
     */
    private static function lineKey(string $grouping, array $line, ?array $article, array $variants): array
    {
        if ($article === null) {
            return $grouping === 'article' && $line['reference'] !== null && $line['reference'] !== ''
                ? ['ref:'.$line['reference'], $line['reference']]
                : [self::NO_ARTICLE, null];
        }

        if ($grouping === 'article') {
            return ['id:'.$line['article_id'], $article['reference']];
        }

        $type = $article['variant'];
        $variant = $type !== null ? ($variants[$type] ?? null) : null;

        if ($grouping === 'variant') {
            return $type !== null ? [$type, $variant['label'] ?? $type] : [self::UNCLASSIFIED, null];
        }

        return match (true) {
            ($variant['own_tab'] ?? false) === true => [self::MATERIAL, null],
            $variant !== null && $variant['counts'] === false => [self::OTHER, null],
            $article['category'] !== null => [$article['category'], $article['category']],
            default => [self::UNCLASSIFIED, null],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function &row(array &$rows, string $key, ?string $label): array
    {
        $rows[$key] ??= ['key' => $key, 'label' => $label, 'deliveries' => [], 'quantity' => 0, 'free_quantity' => 0, 'ht' => 0, 'ttc' => 0, 'ht_by_rate' => []];

        return $rows[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function addDelivery(array &$row, int $id): void
    {
        if (end($row['deliveries']) !== $id) {
            $row['deliveries'][] = $id;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function finishRow(array $row, bool $fromLines): array
    {
        $ttc = $row['ttc'];
        if ($fromLines) {
            ksort($row['ht_by_rate'], SORT_STRING);
            $vat = 0;
            foreach ($row['ht_by_rate'] as $rate => $ht) {
                $vat += (int) round($ht * OrderTotals::toCents($rate) / 10000, 0, PHP_ROUND_HALF_UP);
            }
            $ttc = $row['ht'] + $vat;
        }

        return [
            'key' => $row['key'],
            'label' => $row['label'],
            'count' => count($row['deliveries']),
            'quantity' => OrderTotals::money($row['quantity']),
            'free_quantity' => OrderTotals::money($row['free_quantity']),
            'ht' => OrderTotals::money($row['ht']),
            'ttc' => OrderTotals::money($ttc),
            'deliveries' => $row['deliveries'],
        ];
    }
}
