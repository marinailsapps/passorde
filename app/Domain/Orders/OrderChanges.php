<?php

namespace App\Domain\Orders;

use App\Domain\Clients\ClientChanges;
use App\Domain\Clients\ClientExtraField;
use App\Models\Order;
use App\Models\OrderLine;

/**
 * What an edit changed in an order. Saving overwrites the lines, so every edit
 * keeps the whole order before and after it in the activity log's meta_json:
 * the first "before" is the order as it was created.
 */
final class OrderChanges
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Order $order): array
    {
        $order = Order::query()->with(['client', 'paytype', 'lines.article', 'clientChange'])->findOrFail($order->id);

        return [
            'client' => ['id' => $order->client->id, 'company_name' => $order->client->company_name],
            'paytype' => $order->paytype?->label,
            'comment' => $order->comment,
            'total_quantity' => $order->total_quantity,
            'total_ht' => $order->total_ht,
            'total_ttc' => $order->total_ttc,
            'lines' => $order->lines->map(fn (OrderLine $line) => [
                'article_id' => $line->article_id,
                'reference' => $line->article?->reference,
                'designation' => $line->designation,
                'note' => $line->note,
                'quantity' => $line->quantity,
                'unit_price_ht' => $line->unit_price_ht,
                'is_free' => $line->is_free,
            ])->all(),
            'client_changes' => $order->clientChange?->diff,
        ];
    }

    /**
     * The history line of an "updated" entry; null for entries older than the snapshots.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function detail(?array $meta): ?string
    {
        if (! isset($meta['before'], $meta['after'])) {
            return null;
        }

        return implode("\n", self::describe($meta['before'], $meta['after'])) ?: null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function describe(array $before, array $after): array
    {
        $changes = [];

        if ($before['client']['id'] !== $after['client']['id']) {
            $changes[] = __('Client: :from → :to', ['from' => $before['client']['company_name'], 'to' => $after['client']['company_name']]);
        }

        if ($before['paytype'] !== $after['paytype']) {
            $changes[] = __('Payment: :from → :to', ['from' => $before['paytype'] ?? '—', 'to' => $after['paytype'] ?? '—']);
        }

        if ($before['comment'] !== $after['comment']) {
            $changes[] = __('Comment: :from → :to', ['from' => $before['comment'] ?? '—', 'to' => $after['comment'] ?? '—']);
        }

        return [
            ...$changes,
            ...self::describeLines($before['lines'], $after['lines']),
            ...self::describeCorrection($before['client_changes'] ?? [], $after['client_changes'] ?? []),
        ];
    }

    /**
     * Lines pair up by article and free or paid; what has no pair was added or removed.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private static function describeLines(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $old) {
            $index = array_find_key($after, fn (array $new) => $new['article_id'] === $old['article_id'] && $new['is_free'] === $old['is_free']);

            if ($index === null) {
                $changes[] = __(':line removed: :quantity × :price', self::lineWords($old));

                continue;
            }

            $new = $after[$index];
            unset($after[$index]);

            if ((float) $old['quantity'] !== (float) $new['quantity']) {
                $changes[] = __(':line: quantity :from → :to', ['line' => self::lineName($new), 'from' => self::quantity($old['quantity']), 'to' => self::quantity($new['quantity'])]);
            }

            if ((float) $old['unit_price_ht'] !== (float) $new['unit_price_ht']) {
                $changes[] = __(':line: price :from → :to', ['line' => self::lineName($new), 'from' => self::price($old['unit_price_ht']), 'to' => self::price($new['unit_price_ht'])]);
            }

            // Snapshots older than line comments have no "note".
            if (($old['note'] ?? null) !== ($new['note'] ?? null)) {
                $changes[] = self::noteChange($new, $old['note'] ?? null);
            }
        }

        foreach ($after as $new) {
            $changes[] = __(':line added: :quantity × :price', self::lineWords($new));

            if (($new['note'] ?? null) !== null) {
                $changes[] = self::noteChange($new, null);
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function noteChange(array $line, ?string $from): string
    {
        return __(':line: comment :from → :to', ['line' => self::lineName($line), 'from' => $from ?? '—', 'to' => $line['note'] ?? '—']);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $before
     * @param  array<string, array{from: mixed, to: mixed}>  $after
     * @return list<string>
     */
    private static function describeCorrection(array $before, array $after): array
    {
        $changes = [];
        // The snapshots are jsonb (keys sorted by length): the optional field, last under its old name, stays last.
        $after = self::extraFieldLast($after);
        $before = self::extraFieldLast($before);

        foreach ($after as $field => $change) {
            if (($before[$field]['to'] ?? null) !== $change['to'] || ! array_key_exists($field, $before)) {
                $changes[] = __('Client details, :field: :from → :to', ['field' => ClientChanges::labels([$field]), 'from' => $change['from'] ?? '—', 'to' => $change['to'] ?? '—']);
            }
        }

        foreach (array_keys(array_diff_key($before, $after)) as $field) {
            $changes[] = __('Client details, :field: correction removed', ['field' => ClientChanges::labels([$field])]);
        }

        return $changes;
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $correction
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function extraFieldLast(array $correction): array
    {
        $field = ClientExtraField::ATTRIBUTE;

        return array_key_exists($field, $correction) ? [...array_diff_key($correction, [$field => true]), $field => $correction[$field]] : $correction;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, string>
     */
    private static function lineWords(array $line): array
    {
        return ['line' => self::lineName($line), 'quantity' => self::quantity($line['quantity']), 'price' => self::price($line['unit_price_ht'])];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function lineName(array $line): string
    {
        $name = (string) ($line['reference'] ?? $line['designation']);

        return $line['is_free'] ? __(':reference, free line', ['reference' => $name]) : $name;
    }

    private static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');
    }

    private static function price(mixed $value): string
    {
        return number_format((float) $value, 2, ',', ' ').' €';
    }
}
