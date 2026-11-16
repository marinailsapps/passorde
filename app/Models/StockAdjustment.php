<?php

namespace App\Models;

use App\Enums\EvolizStockStatus;
use App\Enums\StockAdjustmentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A broken entry or a gift outside an order. It also adjusts the Evoliz stock
 * (AdjustStockJob): evoliz_applied says whether Evoliz's stock currently
 * includes the deduction; deleting an entry (soft) adds the quantity back.
 *
 * @property int $id
 * @property int $article_id
 * @property StockAdjustmentKind $kind
 * @property string $quantity
 * @property Carbon $happened_on
 * @property int|null $client_id
 * @property string|null $note
 * @property int $user_id
 * @property EvolizStockStatus|null $evoliz_status
 * @property string|null $evoliz_error
 * @property string|null $evoliz_before
 * @property string|null $evoliz_after
 * @property bool $evoliz_applied
 * @property Carbon $created_at
 * @property Carbon|null $deleted_at
 * @property-read Article $article
 * @property-read Client|null $client
 * @property-read User $user
 */
class StockAdjustment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'article_id', 'kind', 'quantity', 'happened_on', 'client_id', 'note', 'user_id',
        'evoliz_status', 'evoliz_error', 'evoliz_before', 'evoliz_after', 'evoliz_applied',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StockAdjustmentKind::class,
            'quantity' => 'decimal:2',
            'happened_on' => 'date',
            'evoliz_status' => EvolizStockStatus::class,
            'evoliz_before' => 'decimal:2',
            'evoliz_after' => 'decimal:2',
            'evoliz_applied' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EvolizJob, $this>
     */
    public function evolizJobs(): HasMany
    {
        return $this->hasMany(EvolizJob::class);
    }

    /**
     * Evoliz's stock does not match what the entry wants (deducted while it
     * exists, added back once deleted): the job still has something to do.
     */
    public function evolizOutOfStep(): bool
    {
        return $this->evoliz_applied === $this->trashed();
    }
}
