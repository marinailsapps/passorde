<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A delivery note (BL) mirrored from Evoliz by App\Integrations\Sync\Sinks\DeliverySink. Read-only.
 *
 * @property int $id
 * @property int $evoliz_delivery_id
 * @property string $document_number
 * @property string|null $external_document_number
 * @property int|null $evoliz_client_id
 * @property int|null $client_id
 * @property int|null $order_id
 * @property int|null $evoliz_user_id
 * @property string|null $client_name
 * @property int $status_code
 * @property string $status
 * @property bool $invoiced
 * @property Carbon $documentdate
 * @property string $total_ht
 * @property string $total_vat
 * @property string $total_ttc
 * @property string $total_quantity
 * @property string|null $comment
 * @property string|null $lines_hash
 * @property Carbon|null $synced_at
 * @property Carbon|null $deleted_at
 * @property Carbon $updated_at
 * @property-read Client|null $client
 * @property-read Order|null $order
 * @property-read EvolizUser|null $evolizUser
 * @property-read Collection<int, EvolizDeliveryLine> $lines
 */
class EvolizDelivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'evoliz_delivery_id',
        'document_number',
        'external_document_number',
        'evoliz_client_id',
        'client_id',
        'order_id',
        'evoliz_user_id',
        'client_name',
        'status_code',
        'status',
        'invoiced',
        'documentdate',
        'total_ht',
        'total_vat',
        'total_ttc',
        'total_quantity',
        'comment',
        'lines_hash',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evoliz_delivery_id' => 'integer',
            'evoliz_client_id' => 'integer',
            'client_id' => 'integer',
            'order_id' => 'integer',
            'evoliz_user_id' => 'integer',
            'status_code' => 'integer',
            'invoiced' => 'boolean',
            'documentdate' => 'date',
            'total_ht' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'total_quantity' => 'decimal:2',
            'synced_at' => 'datetime',
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
     * @return BelongsTo<EvolizUser, $this>
     */
    public function evolizUser(): BelongsTo
    {
        return $this->belongsTo(EvolizUser::class, 'evoliz_user_id', 'evoliz_user_id');
    }

    /**
     * @return HasMany<EvolizDeliveryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EvolizDeliveryLine::class, 'delivery_id')->orderBy('position');
    }
}
