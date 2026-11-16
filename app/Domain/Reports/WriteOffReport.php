<?php

namespace App\Domain\Reports;

use App\Enums\StockAdjustmentKind;
use App\Enums\WriteOffType;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The write-off report: every article quantity that left the stock
 * without a sale in a period, newest first. Broken entries and gifts outside
 * orders come from the journal (stock_adjustments, by the day they happened;
 * deleted entries are left out), gifts in orders from the free lines of orders
 * sent to Evoliz or, in file mode, exported (by that date, as in the stock report).
 *
 * "recorded_at" is when the author entered it: the journal entry, or the
 * order. The period is the stock report's (StockReport::period).
 */
class WriteOffReport
{
    public const PER_PAGE = 20;

    /**
     * The quick periods of the page, for today in Europe/Paris: this year and
     * this quarter run to today, last year and the previous quarter are whole.
     *
     * @return list<array{key: string, from: string, to: string}>
     */
    public static function presets(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now('Europe/Paris'))->copy()->startOfDay();
        $lastYear = $today->copy()->subYearNoOverflow();
        $lastQuarterEnd = $today->copy()->firstOfQuarter()->subDay();

        $presets = [
            'this_year' => [$today->copy()->startOfYear(), $today],
            'last_year' => [$lastYear->copy()->startOfYear(), $lastYear->copy()->endOfYear()],
            'this_quarter' => [$today->copy()->firstOfQuarter(), $today],
            'last_quarter' => [$lastQuarterEnd->copy()->firstOfQuarter(), $lastQuarterEnd],
        ];

        $list = [];
        foreach ($presets as $key => [$start, $end]) {
            $list[] = ['key' => $key, 'from' => $start->toDateString(), 'to' => $end->toDateString()];
        }

        return $list;
    }

    /**
     * @param  list<WriteOffType>  $types
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(Carbon $from, Carbon $to, array $types): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return $this->ordered($from, $to, $types)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (object $row) => self::row($row));
    }

    /**
     * Every row of the period, for the Excel download.
     *
     * @param  list<WriteOffType>  $types
     * @return list<array<string, mixed>>
     */
    public function rows(Carbon $from, Carbon $to, array $types): array
    {
        return $this->ordered($from, $to, $types)->get()->map(fn (object $row) => self::row($row))->values()->all();
    }

    /**
     * Entries and quantity of the period, in all and per selected type.
     *
     * @param  list<WriteOffType>  $types
     * @return array{entries: int, quantity: string, by_type: array<string, array{entries: int, quantity: string}>}
     */
    public function totals(Carbon $from, Carbon $to, array $types): array
    {
        $sums = DB::query()->fromSub($this->union($from, $to, $types), 'write_offs')
            ->groupBy('type')
            ->get(['type', DB::raw('count(*) as entries'), DB::raw('sum(quantity) as quantity')])
            ->keyBy('type');

        $byType = [];
        foreach ($types as $type) {
            $byType[$type->value] = [
                'entries' => (int) ($sums[$type->value]->entries ?? 0),
                'quantity' => self::qty((float) ($sums[$type->value]->quantity ?? 0)),
            ];
        }

        return [
            'entries' => array_sum(array_column($byType, 'entries')),
            'quantity' => self::qty(array_sum(array_map(fn (array $total) => (float) $total['quantity'], $byType))),
            'by_type' => $byType,
        ];
    }

    /**
     * @param  list<WriteOffType>  $types
     */
    private function ordered(Carbon $from, Carbon $to, array $types): Builder
    {
        return DB::query()->fromSub($this->union($from, $to, $types), 'write_offs')
            ->orderByDesc('date')
            ->orderByDesc('recorded_at')
            ->orderBy('type')
            ->orderByDesc('source_id');
    }

    /**
     * @param  list<WriteOffType>  $types
     */
    private function union(Carbon $from, Carbon $to, array $types): Builder
    {
        $kinds = [];
        if (in_array(WriteOffType::Broken, $types, true)) {
            $kinds[] = StockAdjustmentKind::Broken->value;
        }
        if (in_array(WriteOffType::Gift, $types, true)) {
            $kinds[] = StockAdjustmentKind::Gift->value;
        }

        $journal = DB::table('stock_adjustments')
            ->join('articles', 'articles.id', '=', 'stock_adjustments.article_id')
            ->join('users', 'users.id', '=', 'stock_adjustments.user_id')
            ->leftJoin('clients', 'clients.id', '=', 'stock_adjustments.client_id')
            ->whereNull('stock_adjustments.deleted_at')
            ->whereIn('stock_adjustments.kind', $kinds)
            ->whereBetween('stock_adjustments.happened_on', [$from->toDateString(), $to->toDateString()])
            ->select([
                DB::raw("case when stock_adjustments.kind = 'broken' then '".WriteOffType::Broken->value."' else '".WriteOffType::Gift->value."' end as type"),
                'stock_adjustments.id as source_id',
                'stock_adjustments.happened_on as date',
                'stock_adjustments.created_at as recorded_at',
                'stock_adjustments.quantity',
                'articles.reference',
                DB::raw('articles.designation::text as designation'),
                'clients.company_name as client',
                DB::raw('null::varchar as order_number'),
                DB::raw('null::uuid as order_uuid'),
                'stock_adjustments.note',
                'stock_adjustments.user_id',
                'users.name as user_name',
                'stock_adjustments.evoliz_status',
            ]);

        if (! in_array(WriteOffType::OrderGift, $types, true)) {
            return $journal;
        }

        $orders = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('articles', 'articles.id', '=', 'order_lines.article_id')
            ->where('order_lines.is_free', true)
            ->whereRaw(Order::DELIVERED_SQL)
            ->whereNull('orders.deleted_at')
            ->whereBetween(DB::raw(Order::DELIVERED_AT_SQL), [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->select([
                DB::raw("'".WriteOffType::OrderGift->value."' as type"),
                'order_lines.id as source_id',
                DB::raw('('.Order::DELIVERED_AT_SQL." at time zone 'UTC' at time zone 'Europe/Paris')::date as date"),
                'orders.created_at as recorded_at',
                'order_lines.quantity',
                'articles.reference',
                'order_lines.designation',
                'clients.company_name as client',
                'orders.number as order_number',
                'orders.uuid as order_uuid',
                DB::raw('null::text as note'),
                'orders.user_id',
                'users.name as user_name',
                DB::raw('null::varchar as evoliz_status'),
            ]);

        return $kinds === [] ? $orders : $journal->unionAll($orders);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(object $row): array
    {
        return [
            'key' => $row->type.'-'.$row->source_id,
            'type' => $row->type,
            'date' => Carbon::parse($row->date)->toDateString(),
            'recorded_at' => Carbon::parse($row->recorded_at)->toIso8601String(),
            'quantity' => self::qty((float) $row->quantity),
            'reference' => $row->reference,
            'designation' => $row->designation,
            'client' => $row->client,
            'order_number' => $row->order_number,
            'order_uuid' => $row->order_uuid,
            'note' => $row->note,
            'user' => $row->user_name,
            'user_id' => (int) $row->user_id,
            'evoliz_status' => $row->evoliz_status,
        ];
    }

    private static function qty(float $value): string
    {
        return number_format(round($value, 2) + 0.0, 2, '.', '');
    }
}
