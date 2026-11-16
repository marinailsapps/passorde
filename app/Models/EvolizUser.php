<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Evoliz user seen on delivery notes, mapped to an app user by an admin.
 *
 * @property int $id
 * @property int $evoliz_user_id
 * @property string|null $label
 * @property int|null $user_id
 * @property-read User|null $user
 */
class EvolizUser extends Model
{
    protected $fillable = ['evoliz_user_id', 'label', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['evoliz_user_id' => 'integer', 'user_id' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
