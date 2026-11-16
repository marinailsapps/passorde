<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property OrderStatus $status
 * @property int|null $paytype_id
 * @property string|null $comment
 * @property string $total_quantity
 * @property string $total_ht
 * @property string $total_vat
 * @property string $total_ttc
 * @property string|null $rejected_reason
 * @property int|null $evoliz_delivery_id
 * @property string|null $evoliz_delivery_number
 * @property string|null $evoliz_error
 * @property string|null $evoliz_warning
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $exported_at
 * @property Carbon|null $standalone_at Approved while Evoliz was off or unavailable: never pushed on its own
 * @property int|null $exported_by
 * @property-read User|null $exporter
 * @property-read User|null $approver
 * @property-read Collection<int, ActivityLog> $activity
 * @property-read Collection<int, EvolizJob> $evolizJobs
 * @property-read Collection<int, SmsLog> $smsLogs
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Client $client
 * @property-read Paytype|null $paytype
 * @property-read Collection<int, OrderLine> $lines
 */
class Order extends Model
{
    /**
     * Delivered: a BL exists (sent) or the order was exported. No mode check:
     * exported_at is set while Evoliz does not run (file mode, or a lapse), or, with
     * Evoliz back, by an admin for an order approved without it (standalone_at,
     * ApprovalService::exportableHere); either way the order keeps it and is never
     * pushed (Order::wasEverExported). When its hand-made BL arrives and is linked
     * by order_id, the BL is that sale: the history and Ventes never count both.
     * Dated by DELIVERED_AT_SQL.
     */
    public const DELIVERED_SQL = "(orders.status = 'sent' OR orders.exported_at IS NOT NULL)";

    public const DELIVERED_AT_SQL = 'coalesce(orders.sent_at, orders.exported_at)';

    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number',
        'uuid',
        'user_id',
        'client_id',
        'status',
        'paytype_id',
        'comment',
        'total_quantity',
        'total_ht',
        'total_vat',
        'total_ttc',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_quantity' => 'decimal:2',
            'total_ht' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'exported_at' => 'datetime',
            'standalone_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Paytype, $this>
     */
    public function paytype(): BelongsTo
    {
        return $this->belongsTo(Paytype::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereRaw(self::DELIVERED_SQL);
    }

    /**
     * File mode: approved, waiting for the operator's export.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeToExport(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Approved)->whereNull('exported_at');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeExported(Builder $query): Builder
    {
        return $query->whereNotNull('exported_at');
    }

    /**
     * Exported now, or once and then unmarked: the operator may have made its BL by hand
     * from that file, so it is never sent to Evoliz (ApprovalService::sendToEvoliz).
     */
    public function wasEverExported(): bool
    {
        return $this->exported_at !== null
            || ActivityLog::query()->where('order_id', $this->id)->where('action', ActivityAction::Exported)->exists();
    }

    /**
     * The last time this order was in a downloaded export file (activity "export_downloaded"), or null.
     * Marked exported or not, its BL may have been typed by hand from that file.
     */
    public function lastDownloadedAt(): ?Carbon
    {
        $at = ActivityLog::query()->where('order_id', $this->id)->where('action', ActivityAction::ExportDownloaded)->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * Downloaded in an export file and not confirmed since: no push may send it. The admin's "Envoyer à Evoliz"
     * with the explicit confirmation (activity "sent_to_evoliz" with confirmed_download) lifts it.
     */
    public function downloadUnconfirmed(): bool
    {
        $downloaded = $this->lastDownloadedAt();

        return $downloaded !== null && ! ActivityLog::query()->where('order_id', $this->id)->where('action', ActivityAction::SentToEvoliz)
            ->where('created_at', '>=', $downloaded)->where('meta_json->confirmed_download', true)->exists();
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activity(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->orderBy('id');
    }

    /**
     * @return HasMany<EvolizJob, $this>
     */
    public function evolizJobs(): HasMany
    {
        return $this->hasMany(EvolizJob::class)->orderBy('id');
    }

    /**
     * @return HasMany<SmsLog, $this>
     */
    public function smsLogs(): HasMany
    {
        return $this->hasMany(SmsLog::class)->orderBy('id');
    }

    /**
     * The client details corrected with this order, if any.
     *
     * @return HasOne<ClientChange, $this>
     */
    public function clientChange(): HasOne
    {
        return $this->hasOne(ClientChange::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /** Until it is approved: by its author, or by the operator (an admin), who corrects it before approving. */
    public function isEditableBy(User $user): bool
    {
        return $this->status === OrderStatus::Pending
            && ($this->user_id === $user->id || $user->isAdmin())
            && ! $this->trashed();
    }

    /** A rejected order never reached Evoliz: its author or an admin may remove it from the lists. */
    public function isDeletableBy(User $user): bool
    {
        if ($this->status === OrderStatus::Rejected && $user->isAdmin() && ! $this->trashed()) {
            return true;
        }

        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Rejected], true) && $this->user_id === $user->id && ! $this->trashed();
    }
}
