<?php

namespace App\Models;

use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change of a client's details (App\Domain\Clients\ClientChanges).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $order_id
 * @property int|null $user_id
 * @property ClientChangeSource $source
 * @property ClientChangeStatus $status
 * @property array<string, array{from: string|null, to: string|null}> $diff only the fields that differ
 * @property int|null $decided_by
 * @property Carbon|null $applied_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ClientChange extends Model
{
    protected $fillable = [
        'client_id',
        'order_id',
        'user_id',
        'source',
        'status',
        'diff',
        'decided_by',
        'applied_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ClientChangeSource::class,
            'status' => ClientChangeStatus::class,
            'diff' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The new values, field => value.
     *
     * @return array<string, string|null>
     */
    public function newValues(): array
    {
        return array_map(fn (array $pair) => $pair['to'], $this->diff);
    }

    public function touchesEvoliz(): bool
    {
        return array_diff(array_keys($this->diff), ['extra_field']) !== [];
    }
}
