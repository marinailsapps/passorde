<?php

namespace App\Integrations\Sync\Sinks;

use App\Models\Article;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizUser;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Passorde\Records\DeliveryRecord;
use Passorde\Sinks\Contracts\DeliverySink as DeliverySinkContract;

/**
 * The Evoliz delivery notes of a date window (documentdate), with their lines,
 * into evoliz_deliveries / evoliz_delivery_lines, for the sales history and the
 * sales report (the Evoliz connector notes in Passorde Connect "Delivery mirror").
 *
 * A BL is matched by its Evoliz id; its lines are rewritten only when they
 * changed (lines_hash), all with one timestamp, so the phones' delta gets a
 * BL's lines together. A BL of the window that Evoliz no longer returns is
 * soft-deleted. BLs are linked to the client (evoliz_client_id), to the app
 * order they came from (evoliz_delivery_id, else external_document_number =
 * order number) and to the Evoliz user who made them (UserSink names them).
 */
final class DeliverySink implements DeliverySinkContract
{
    /**
     * @param  list<DeliveryRecord>  $records
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $rows = array_map(fn (DeliveryRecord $record): array => $record->raw, $records);
        $deliveryIds = array_map(fn (DeliveryRecord $record): int => $record->id, $records);
        $clientIds = array_values(array_filter(array_map(fn (DeliveryRecord $record): ?int => $record->clientId, $records)));
        $articleIds = array_values(array_unique(array_filter(array_merge(...array_map(
            fn (DeliveryRecord $record): array => array_map(fn ($line): ?int => $line->articleId, $record->lines),
            $records,
        )))));

        $clients = Client::query()->whereIn('evoliz_client_id', $clientIds)->pluck('id', 'evoliz_client_id')->all();
        $articles = Article::query()->whereIn('evoliz_article_id', $articleIds)->pluck('id', 'evoliz_article_id')->all();
        $ordersByDelivery = Order::withTrashed()->whereIn('evoliz_delivery_id', $deliveryIds)->pluck('id', 'evoliz_delivery_id')->all();
        $changed = 0;

        foreach ($rows as $row) {
            $userId = (int) ($row['userid'] ?? 0);

            if ($userId !== 0) {
                EvolizUser::query()->firstOrCreate(['evoliz_user_id' => $userId]);
            }

            $changed += $this->store($row, $clients, $articles, $ordersByDelivery) ? 1 : 0;
        }

        return $changed;
    }

    public function removeMissing(CarbonImmutable $from, CarbonImmutable $to, array $seenIds, CarbonImmutable $syncedAt): int
    {
        $gone = EvolizDelivery::query()
            ->whereBetween('documentdate', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('evoliz_delivery_id', $seenIds)
            ->get();

        foreach ($gone as $delivery) {
            $delivery->delete();
        }

        return $gone->count();
    }

    /**
     * @param  array<array-key, mixed>  $row  One delivery from GET /deliveries
     * @param  array<int, int>  $clients  evoliz_client_id → client id
     * @param  array<int, int>  $articles  evoliz_article_id → article id
     * @param  array<int, int>  $ordersByDelivery  evoliz_delivery_id → order id (orders the app pushed)
     */
    private function store(array $row, array $clients, array $articles, array $ordersByDelivery): bool
    {
        $evolizId = (int) $row['deliveryid'];
        $lines = self::lines($row, $articles);
        $hash = hash('sha256', (string) json_encode($lines));

        $delivery = EvolizDelivery::withTrashed()->where('evoliz_delivery_id', $evolizId)->first() ?? new EvolizDelivery(['evoliz_delivery_id' => $evolizId]);
        $mapped = self::map($row);
        $external = $mapped['external_document_number'];
        $delivery->fill([
            ...$mapped,
            'client_id' => $mapped['evoliz_client_id'] !== null ? ($clients[$mapped['evoliz_client_id']] ?? null) : null,
            'order_id' => $ordersByDelivery[$evolizId]
                ?? ($external !== null ? Order::withTrashed()->where('number', $external)->value('id') : null),
            'lines_hash' => $hash,
        ]);

        $restored = $delivery->trashed();
        // A restored BL sends its lines again: the phones dropped them with the BL.
        $linesChanged = ! $delivery->exists || $restored || $delivery->getOriginal('lines_hash') !== $hash;

        if (! $delivery->isDirty() && ! $restored) {
            return false;
        }

        DB::transaction(function () use ($delivery, $lines, $linesChanged, $restored) {
            $now = now();
            $delivery->synced_at = $now;

            if ($restored) {
                $delivery->deleted_at = null;
            }

            $delivery->save();

            if ($linesChanged) {
                $delivery->lines()->delete();
                $delivery->lines()->insert(array_map(fn (array $line) => [...$line, 'delivery_id' => $delivery->id, 'created_at' => $now, 'updated_at' => $now], $lines));
            }
        });

        return true;
    }

    /**
     * The delivery's own fields, without links to app rows.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    public static function map(array $row): array
    {
        $code = (int) ($row['status_code'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $clientId = (int) data_get($row, 'client.clientid', 0);
        $userId = (int) ($row['userid'] ?? 0);

        return [
            'document_number' => (string) ($row['document_number'] ?? ''),
            'external_document_number' => EvolizText::text($row['external_document_number'] ?? null),
            'evoliz_client_id' => $clientId !== 0 ? $clientId : null,
            'evoliz_user_id' => $userId !== 0 ? $userId : null,
            'client_name' => EvolizText::plain(data_get($row, 'client.name')),
            'status_code' => $code,
            'status' => $status,
            'invoiced' => self::isInvoiced($code, $status, data_get($row, 'status_dates.invoice')),
            'documentdate' => (string) ($row['documentdate'] ?? ''),
            'total_ht' => EvolizText::money(data_get($row, 'total.vat_exclude')),
            'total_vat' => EvolizText::money(data_get($row, 'total.vat')),
            'total_ttc' => EvolizText::money(data_get($row, 'total.vat_include')),
            'total_quantity' => EvolizText::money(data_get($row, 'total.total_quantity')),
            'comment' => EvolizText::plain($row['comment_clean'] ?? $row['comment'] ?? null),
        ];
    }

    /**
     * Invoiced: status 20 "invoice" or 24 "close", or an invoice date.
     */
    public static function isInvoiced(int $code, string $status, mixed $invoicedAt): bool
    {
        return in_array($code, [20, 24], true) || in_array($status, ['invoice', 'close'], true) || (is_string($invoicedAt) && $invoicedAt !== '');
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  array<int, int>  $articles  evoliz_article_id → article id
     * @return list<array<string, mixed>>
     */
    public static function lines(array $row, array $articles): array
    {
        $items = is_array($row['items'] ?? null) ? array_values(array_filter($row['items'], 'is_array')) : [];
        $lines = [];

        foreach ($items as $position => $item) {
            $articleId = (int) ($item['articleid'] ?? 0);
            $price = EvolizText::money($item['unit_price_vat_exclude'] ?? 0);
            $vat = $item['vat'] ?? null;

            $lines[] = [
                'position' => $position,
                'evoliz_article_id' => $articleId !== 0 ? $articleId : null,
                'article_id' => $articleId !== 0 ? ($articles[$articleId] ?? null) : null,
                'reference' => EvolizText::plain($item['reference_clean'] ?? $item['reference'] ?? null),
                'designation' => EvolizText::cleanDesignation($item['designation_clean'] ?? $item['designation'] ?? ''),
                'quantity' => EvolizText::money($item['quantity'] ?? 0),
                'unit_price_ht' => $price,
                'vat_rate' => is_numeric($vat) ? EvolizText::money($vat) : null,
                'total_ht' => EvolizText::money(data_get($item, 'total.vat_exclude', 0)),
                'is_free' => (float) $price === 0.0,
            ];
        }

        return $lines;
    }
}
