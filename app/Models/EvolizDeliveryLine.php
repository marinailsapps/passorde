<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One item of a mirrored delivery note. A line at 0 € is a free line (or a gift).
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $position
 * @property int|null $evoliz_article_id
 * @property int|null $article_id
 * @property string|null $reference
 * @property string $designation
 * @property string $quantity
 * @property string $unit_price_ht
 * @property string|null $vat_rate
 * @property string $total_ht
 * @property bool $is_free
 * @property Carbon $updated_at
 * @property-read Article|null $article
 */
class EvolizDeliveryLine extends Model
{
    protected $fillable = [
        'delivery_id',
        'position',
        'evoliz_article_id',
        'article_id',
        'reference',
        'designation',
        'quantity',
        'unit_price_ht',
        'vat_rate',
        'total_ht',
        'is_free',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_id' => 'integer',
            'position' => 'integer',
            'evoliz_article_id' => 'integer',
            'article_id' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'total_ht' => 'decimal:2',
            'is_free' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
