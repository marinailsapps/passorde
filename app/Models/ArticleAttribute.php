<?php

namespace App\Models;

use App\Enums\AttributeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Report data of one article (category, variant, reorder threshold; App\Domain\Catalog\Catalog), which
 * Evoliz does not have: from ArticleClassifier, a workbook (reports:seed-attributes) or an admin.
 *
 * @property int $id
 * @property int $article_id
 * @property string|null $category the category's stored value
 * @property string $variant the variant's stored value
 * @property int|null $reorder_threshold
 * @property bool $needs_review
 * @property AttributeSource $source
 * @property int|null $updated_by
 * @property Carbon $updated_at
 * @property-read Article $article
 */
class ArticleAttribute extends Model
{
    /**
     * A new category or variant reaches the phones' article mirror (sales report offline).
     *
     * @var list<string>
     */
    protected $touches = ['article'];

    protected $fillable = ['article_id', 'category', 'variant', 'reorder_threshold', 'needs_review', 'source', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reorder_threshold' => 'integer',
            'needs_review' => 'boolean',
            'source' => AttributeSource::class,
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
