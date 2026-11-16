<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\Catalog;
use App\Enums\StockAdjustmentKind;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Models\CategoryThreshold;
use App\Models\Order;
use App\Models\StockAdjustment;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The stock report (docs/API.md "Reports"), built
 * from the Evoliz catalogue and the app's own data. One tab per category group
 * of the profile (catalog.category_groups, a variant's own tab among them), "À
 * classer" (articles without a sure category), then the broken entries and the
 * gifts of the period; the tab labels come from the profile (reports.stock_tabs).
 *
 * Per article: stock (Evoliz, current), réservé (app orders pending, approved
 * or failed, current), cassées and cadeaux hors commande (journal entries of
 * the period that Evoliz's stock does not include yet: AdjustStockJob
 * writes them to Evoliz, so in normal operation these are only the ones still
 * pending, failed or recorded in test mode; an entry deleted while Evoliz still
 * has it deducted counts back as a positive amount), à terme = stock − réservé
 * − cassées − cadeaux, seuil (the article's, else its category's, else none),
 * à commander = max(0, seuil − à terme).
 *
 * Active articles: stock above 0, or on an order (not rejected), an Evoliz
 * delivery note (any BL, also those typed in Evoliz) or a journal entry in the
 * last 12 months, or a threshold set on the article itself. The
 * category's threshold applies to active articles only, so old articles nobody
 * buys any more never show up in "à commander".
 * Réservé, cassées and cadeaux are given as negative numbers, as in the file.
 * Quantities are strings with two decimals, like the rest of the API.
 */
class StockReport
{
    public const UNCLASSIFIED = 'a_classer';

    /** With no categories in the profile: one plain list of the articles (no "À classer"). */
    public const ALL = 'articles';

    public const GIFTS = 'gifts';

    public const MATERIAL_GIFTS = 'material_gifts';

    public const BROKEN = 'broken';

    /** The keys of the tabs that are not a category group (a profile's group key must differ). */
    public const FIXED_TABS = [self::UNCLASSIFIED, self::ALL, self::BROKEN, self::GIFTS, self::MATERIAL_GIFTS];

    /** How far back an order or a journal entry makes an article active. */
    public const ACTIVE_MONTHS = 12;

    private const ACTIVE_SQL = <<<'SQL'
        (coalesce(articles.stock_qty, 0) > 0
         OR article_attributes.reorder_threshold IS NOT NULL
         OR EXISTS (SELECT 1 FROM order_lines JOIN orders ON orders.id = order_lines.order_id
                    WHERE order_lines.article_id = articles.id AND orders.status <> 'rejected'
                      AND orders.deleted_at IS NULL AND orders.created_at >= ?)
         OR EXISTS (SELECT 1 FROM stock_adjustments
                    WHERE stock_adjustments.article_id = articles.id AND stock_adjustments.deleted_at IS NULL
                      AND stock_adjustments.happened_on >= ?)
         OR EXISTS (SELECT 1 FROM evoliz_delivery_lines JOIN evoliz_deliveries ON evoliz_deliveries.id = evoliz_delivery_lines.delivery_id
                    WHERE evoliz_delivery_lines.article_id = articles.id AND evoliz_deliveries.deleted_at IS NULL
                      AND evoliz_deliveries.documentdate >= ?))
        SQL;

    /**
     * The period of a request: from/to (Y-m-d, Europe/Paris), default the current year to today.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(?string $from, ?string $to): array
    {
        $today = Carbon::now('Europe/Paris');
        $start = $from !== null ? Carbon::createFromFormat('Y-m-d', $from, 'Europe/Paris') : $today->copy()->startOfYear();
        $end = $to !== null ? Carbon::createFromFormat('Y-m-d', $to, 'Europe/Paris') : $today->copy();

        return $start->lte($end) ? [$start->startOfDay(), $end->startOfDay()] : [$end->startOfDay(), $start->startOfDay()];
    }

    /**
     * @return array{period: array{from: string, to: string}, synced_at: string|null, tabs: list<array<string, mixed>>}
     */
    public function build(Carbon $from, Carbon $to): array
    {
        [$fromUtc, $toUtc] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $thresholds = CategoryThreshold::query()->get()->mapWithKeys(fn (CategoryThreshold $t) => [$t->category => $t->threshold])->all();
        $adjustments = $this->adjustmentTotals($from, $to);

        $articles = DB::table('articles')
            ->leftJoin('article_attributes', 'article_attributes.article_id', '=', 'articles.id')
            ->leftJoin('article_reservations', 'article_reservations.article_id', '=', 'articles.id')
            ->where('articles.is_active', true)
            ->where(fn ($query) => $query->whereNull('article_attributes.variant')->orWhereNotIn('article_attributes.variant', self::leftOut()))
            ->orderBy('articles.reference')
            ->orderBy('articles.id')
            ->addBinding([$since = now()->subMonths(self::ACTIVE_MONTHS), $since->toDateString(), $since->toDateString()], 'select')
            ->get([
                'articles.id', 'articles.reference', 'articles.designation', 'articles.is_stocked', 'articles.stock_qty', 'articles.supplier_name',
                'article_attributes.category', 'article_attributes.variant', 'article_attributes.reorder_threshold', 'article_attributes.needs_review',
                DB::raw('coalesce(article_reservations.reserved_qty, 0) as reserved_qty'),
                DB::raw(self::ACTIVE_SQL.' as is_active_article'),
            ]);

        $groups = [];
        $keys = array_column(Catalog::groups(), 'key');
        foreach ($articles as $article) {
            $key = $this->tabOf($article, $keys);
            if ($key === self::UNCLASSIFIED && Catalog::categories() === []) {
                $key = self::ALL;
            }
            $groups[$key][] = $this->row($article, $thresholds, $adjustments[$article->id] ?? []);
        }

        $tabs = [];
        $owned = Catalog::variantGroups();
        $plain = Catalog::categories() === [];
        // Without categories nothing waits to be classified: the articles are one list, beside any variant's own tab.
        $stockKeys = $plain ? [...$owned, self::ALL] : [...$keys, self::UNCLASSIFIED];
        foreach ($stockKeys as $key) {
            $rows = $groups[$key] ?? [];
            $tabs[] = [
                'key' => $key,
                'label' => match ($key) {
                    self::UNCLASSIFIED => self::tabLabel('unclassified'),
                    self::ALL => self::tabLabel('all'),
                    default => Catalog::groupLabel($key),
                },
                'kind' => 'stock',
                // The tab of a variant (its articles have no category), not of a category group.
                'variant' => in_array($key, $owned, true),
                'rows' => $rows,
                'totals' => $this->totals($rows),
            ];
        }

        // Broken entries, gifts, gifts of the variants with their own tab (reports.stock_tabs).
        $broken = $this->broken($from, $to);
        $tabs[] = ['key' => self::BROKEN, 'label' => self::tabLabel('broken'), 'kind' => 'broken', 'rows' => $broken, 'totals' => ['quantity' => self::sum($broken, 'quantity')]];
        [$articleGifts, $materialGifts] = $this->gifts($fromUtc, $toUtc);
        $tabs[] = ['key' => self::GIFTS, 'label' => self::tabLabel('gifts'), 'kind' => 'gifts', 'rows' => $articleGifts, 'totals' => ['quantity' => self::sum($articleGifts, 'quantity')]];
        // Only with a variant that has its own tab: otherwise this tab could never hold a row.
        if ($owned !== []) {
            $tabs[] = ['key' => self::MATERIAL_GIFTS, 'label' => self::tabLabel('material_gifts'), 'kind' => 'gifts', 'rows' => $materialGifts, 'totals' => ['quantity' => self::sum($materialGifts, 'quantity')]];
        }

        $synced = SyncRun::query()->where('kind', SyncKind::Articles)->where('status', SyncStatus::Succeeded)->latest('finished_at')->value('finished_at');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'synced_at' => $synced !== null ? Carbon::parse($synced)->toIso8601String() : null,
            'tabs' => $tabs,
        ];
    }

    /** A fixed tab's label (reports.stock_tabs: all, unclassified, broken, gifts, material_gifts). */
    public static function tabLabel(string $tab): string
    {
        return (string) config("passorde.reports.stock_tabs.{$tab}");
    }

    /** The report's title (reports.stock_title). */
    public static function title(): string
    {
        return (string) config('passorde.reports.stock_title');
    }

    /**
     * Variants the report leaves out (Catalog::leftOutOfStock).
     *
     * @return list<string>
     */
    private static function leftOut(): array
    {
        return array_values(array_filter(Catalog::variantValues(), fn (string $variant) => Catalog::leftOutOfStock($variant)));
    }

    /**
     * The tab of an article: its variant's own tab, else its category's group; "À CLASSER" without a sure
     * category, and for a category or variant whose group the profile does not list (it never disappears).
     *
     * @param  list<string>  $tabs  The group keys of the report
     */
    private function tabOf(object $article, array $tabs): string
    {
        $owned = Catalog::variantGroup($article->variant);
        if ($owned !== null) {
            return in_array($owned, $tabs, true) ? $owned : self::UNCLASSIFIED;
        }

        if ($article->category === null || $article->needs_review) {
            return self::UNCLASSIFIED;
        }

        $group = Catalog::categoryGroup($article->category);

        return $group !== null && in_array($group, $tabs, true) ? $group : self::UNCLASSIFIED;
    }

    /**
     * @param  array<string, int>  $thresholds
     * @param  array<string, float>  $adjusted  broken / gift totals of the period
     * @return array<string, mixed>
     */
    private function row(object $article, array $thresholds, array $adjusted): array
    {
        $stock = $article->is_stocked && $article->stock_qty !== null ? (float) $article->stock_qty : null;
        $reserved = (float) $article->reserved_qty;
        $broken = $adjusted[StockAdjustmentKind::Broken->value] ?? 0.0;
        $gifts = $adjusted[StockAdjustmentKind::Gift->value] ?? 0.0;
        $atTerm = $stock === null ? null : $stock - $reserved - $broken - $gifts;
        $active = (bool) $article->is_active_article;
        $threshold = $article->reorder_threshold ?? ($active && $article->category !== null ? ($thresholds[$article->category] ?? null) : null);
        $threshold = $threshold === null ? null : (int) $threshold;

        return [
            'article_id' => (int) $article->id,
            'reference' => $article->reference,
            'designation' => $article->designation,
            'category' => $article->category,
            'variant' => $article->variant,
            'type_label' => Catalog::variantLabel($article->variant),
            'stock' => self::qty($stock),
            'reserved' => self::qty(-$reserved),
            'broken' => self::qty(-$broken),
            'gifts' => self::qty(-$gifts),
            'at_term' => self::qty($atTerm),
            'threshold' => $threshold,
            'to_order' => $threshold === null || $atTerm === null ? null : self::qty(max(0.0, $threshold - $atTerm)),
            'supplier' => $article->supplier_name,
            'active' => $active,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $totals = [];
        foreach (['stock', 'reserved', 'broken', 'gifts', 'at_term', 'to_order'] as $column) {
            $totals[$column] = self::sum($rows, $column);
        }

        return $totals;
    }

    /**
     * Broken entries and gifts outside orders per article, in the period, that
     * Evoliz's stock does not reflect yet: entries not applied there (minus) and
     * deleted entries Evoliz has not got back yet (plus).
     *
     * @return array<int, array<string, float>>
     */
    private function adjustmentTotals(Carbon $from, Carbon $to): array
    {
        $totals = [];
        $rows = StockAdjustment::withTrashed()
            ->whereBetween('happened_on', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('deleted_at')->where('evoliz_applied', false))
                ->orWhere(fn ($q) => $q->whereNotNull('deleted_at')->where('evoliz_applied', true)))
            ->toBase()
            ->selectRaw('article_id, kind, sum(case when deleted_at is null then quantity else -quantity end) as quantity')
            ->groupBy('article_id', 'kind')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->article_id][(string) $row->kind] = (float) $row->quantity;
        }

        return $totals;
    }

    /**
     * Free lines of delivered orders (by sending or export date) and gifts
     * outside orders, newest first; split into those of the variants with their
     * own tab (reports.stock_tabs.material_gifts) and the others.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function gifts(Carbon $fromUtc, Carbon $toUtc): array
    {
        $fromOrders = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->leftJoin('articles', 'articles.id', '=', 'order_lines.article_id')
            ->leftJoin('article_attributes', 'article_attributes.article_id', '=', 'order_lines.article_id')
            ->where('order_lines.is_free', true)
            ->whereRaw(Order::DELIVERED_SQL)
            ->whereNull('orders.deleted_at')
            ->whereBetween(DB::raw(Order::DELIVERED_AT_SQL), [$fromUtc->copy()->utc(), $toUtc->copy()->utc()])
            ->get([
                DB::raw(Order::DELIVERED_AT_SQL.' as at'), 'orders.number', 'orders.uuid', 'clients.company_name', 'articles.reference',
                'order_lines.designation', 'order_lines.quantity', 'article_attributes.variant',
            ])
            ->map(fn (object $line) => [
                'date' => Carbon::parse($line->at)->timezone('Europe/Paris')->toDateString(),
                'source' => 'order',
                'order_number' => $line->number,
                'order_uuid' => $line->uuid,
                'client' => $line->company_name,
                'reference' => $line->reference,
                'designation' => $line->designation,
                'quantity' => self::qty((float) $line->quantity),
                'note' => null,
                'evoliz_status' => null,
                'material' => Catalog::variantGroup($line->variant) !== null,
            ]);

        $outside = StockAdjustment::query()
            ->with(['article.attribute', 'client', 'user'])
            ->where('kind', StockAdjustmentKind::Gift)
            ->whereBetween('happened_on', [$fromUtc->toDateString(), $toUtc->toDateString()])
            ->get()
            ->map(fn (StockAdjustment $gift) => [
                'date' => $gift->happened_on->toDateString(),
                'source' => 'adjustment',
                'order_number' => null,
                'order_uuid' => null,
                'client' => $gift->client?->company_name,
                'reference' => $gift->article->reference,
                'designation' => $gift->article->designation,
                'quantity' => self::qty((float) $gift->quantity),
                'note' => $gift->note,
                'evoliz_status' => $gift->evoliz_status?->value,
                'material' => Catalog::variantGroup($gift->article->attribute?->variant) !== null,
            ]);

        $all = $fromOrders->concat($outside)->sortBy([['date', 'desc'], ['reference', 'asc']])->values();
        $strip = fn (array $row) => array_diff_key($row, ['material' => true]);

        return [
            $all->reject(fn (array $row) => $row['material'])->map($strip)->values()->all(),
            $all->filter(fn (array $row) => $row['material'])->map($strip)->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function broken(Carbon $from, Carbon $to): array
    {
        return StockAdjustment::query()
            ->with(['article', 'user'])
            ->where('kind', StockAdjustmentKind::Broken)
            ->whereBetween('happened_on', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('happened_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StockAdjustment $broken) => [
                'date' => $broken->happened_on->toDateString(),
                'reference' => $broken->article->reference,
                'designation' => $broken->article->designation,
                'quantity' => self::qty((float) $broken->quantity),
                'note' => $broken->note,
                'user' => $broken->user->name,
                'evoliz_status' => $broken->evoliz_status?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function sum(array $rows, string $column): string
    {
        return number_format(array_sum(array_map(fn (array $row) => (float) ($row[$column] ?? 0), $rows)), 2, '.', '');
    }

    private static function qty(?float $value): ?string
    {
        // round() first: -0.0 would print as "-0.00".
        return $value === null ? null : number_format(round($value, 2) + 0.0, 2, '.', '');
    }
}
