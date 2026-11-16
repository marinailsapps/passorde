<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $category a category's stored value
 * @property string $variant a variant's stored value
 * @property string $unit_price_ht
 * @property string $reference
 * @property string $designation
 * @property string $status
 * @property string|null $error
 * @property int|null $article_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Article|null $article
 */
class ArticleRequest extends Model
{
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'category',
        'variant',
        'unit_price_ht',
        'reference',
        'designation',
        'status',
        'error',
        'article_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_ht' => 'decimal:2',
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
