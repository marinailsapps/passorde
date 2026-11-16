<?php

namespace App\Domain\Sales;

use App\Models\EvolizDelivery;
use App\Models\EvolizDeliveryLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mirrored delivery notes as the API and the phones see them (docs/API.md
 * "Sales history"). The seller is the author of the app order the BL came
 * from, else the app user mapped to its Evoliz user, else "Evoliz"; the
 * comment is the order's, else the BL's.
 */
final class DeliveryPresenter
{
    public const UNMAPPED_SELLER = 'Evoliz';

    /**
     * Eager loads for row().
     *
     * @param  Builder<EvolizDelivery>  $query
     * @return Builder<EvolizDelivery>
     */
    public static function withRelations(Builder $query): Builder
    {
        return $query->with(['order.user', 'evolizUser.user']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(EvolizDelivery $delivery): array
    {
        [$sellerId, $seller] = self::seller($delivery);

        return [
            'id' => $delivery->id,
            'document_number' => $delivery->document_number,
            'client_id' => $delivery->client_id,
            'client_name' => $delivery->client_name,
            'order_uuid' => $delivery->order?->uuid,
            'order_number' => $delivery->order?->number,
            'date' => $delivery->documentdate->toDateString(),
            'invoiced' => $delivery->invoiced,
            'seller_id' => $sellerId,
            'seller' => $seller,
            'comment' => $delivery->order->comment ?? $delivery->comment,
            'total_ht' => $delivery->total_ht,
            'total_vat' => $delivery->total_vat,
            'total_ttc' => $delivery->total_ttc,
            'deleted_at' => $delivery->deleted_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(EvolizDeliveryLine $line): array
    {
        return [
            'id' => $line->id,
            'delivery_id' => $line->delivery_id,
            'position' => $line->position,
            'article_id' => $line->article_id,
            'reference' => $line->reference,
            'designation' => $line->designation,
            'quantity' => $line->quantity,
            'unit_price_ht' => $line->unit_price_ht,
            'vat_rate' => $line->vat_rate,
            'is_free' => $line->is_free,
            'total_ht' => $line->total_ht,
            'updated_at' => $line->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    public static function seller(EvolizDelivery $delivery): array
    {
        $user = $delivery->order->user ?? $delivery->evolizUser?->user;

        return $user !== null ? [$user->id, $user->name] : [null, self::UNMAPPED_SELLER];
    }
}
