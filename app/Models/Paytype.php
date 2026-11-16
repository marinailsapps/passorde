<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $evoliz_paytype_id
 * @property string $label
 * @property bool $is_enabled
 */
class Paytype extends Model
{
    protected $fillable = [
        'evoliz_paytype_id',
        'label',
        'is_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evoliz_paytype_id' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
