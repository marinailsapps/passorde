<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $position
 * @property int|null $article_id
 * @property string $designation
 * @property string|null $note
 * @property string $quantity
 * @property string $unit_price_ht
 * @property string $vat_rate
 * @property bool $is_free
 * @property string $total_ht
 * @property-read Article|null $article
 */
class OrderLine extends Model
{
    protected $fillable = [
        'position',
        'article_id',
        'designation',
        'note',
        'quantity',
        'unit_price_ht',
        'vat_rate',
        'is_free',
        'total_ht',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'article_id' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'is_free' => 'boolean',
            'total_ht' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
