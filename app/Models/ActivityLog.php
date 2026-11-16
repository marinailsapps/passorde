<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $order_id
 * @property ActivityAction $action
 * @property array<string, mixed>|null $meta_json
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $fillable = ['user_id', 'order_id', 'action', 'meta_json'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'meta_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(ActivityAction $action, ?User $user, ?Order $order, array $meta = []): self
    {
        return static::create([
            'user_id' => $user?->id,
            'order_id' => $order?->id,
            'action' => $action,
            'meta_json' => $meta === [] ? null : $meta,
        ]);
    }
}
