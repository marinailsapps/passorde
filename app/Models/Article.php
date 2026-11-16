<?php

namespace App\Models;

use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $evoliz_article_id
 * @property string $reference
 * @property string $designation
 * @property string|null $designation_raw
 * @property string $unit_price_ht
 * @property string $vat_rate
 * @property int|null $sale_classification_id
 * @property string|null $supplier_name
 * @property string|null $sale_classification_code
 * @property bool $is_stocked
 * @property string|null $stock_qty
 * @property bool $is_active
 * @property Carbon|null $synced_at
 * @property-read ArticleAttribute|null $attribute
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected $fillable = [
        'evoliz_article_id',
        'reference',
        'designation',
        'designation_raw',
        'unit_price_ht',
        'vat_rate',
        'sale_classification_id',
        'supplier_name',
        'sale_classification_code',
        'is_stocked',
        'stock_qty',
        'is_active',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evoliz_article_id' => 'integer',
            'unit_price_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sale_classification_id' => 'integer',
            'is_stocked' => 'boolean',
            'stock_qty' => 'decimal:2',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<ArticleAttribute, $this>
     */
    public function attribute(): HasOne
    {
        return $this->hasOne(ArticleAttribute::class);
    }

    /**
     * Adds reserved_qty: quantity held by pending and approved orders (article_reservations view).
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeWithReserved(Builder $query): Builder
    {
        return $query
            ->leftJoin('article_reservations', 'article_reservations.article_id', '=', 'articles.id')
            ->select('articles.*')
            ->selectRaw('coalesce(article_reservations.reserved_qty, 0) as reserved_qty');
    }
}
