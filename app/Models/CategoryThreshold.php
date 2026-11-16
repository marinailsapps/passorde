<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Reorder threshold of a category, in quantities ("à commander" in the stock report);
 * an article's own reorder_threshold wins over it.
 *
 * @property int $id
 * @property string $category a category's stored value
 * @property int $threshold
 * @property int|null $updated_by
 */
class CategoryThreshold extends Model
{
    protected $fillable = ['category', 'threshold', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['threshold' => 'integer'];
    }
}
