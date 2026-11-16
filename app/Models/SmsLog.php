<?php

namespace App\Models;

use App\Enums\SmsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One SMS attempt to the client of a sent order (SendOrderSmsJob).
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $phone
 * @property string $text
 * @property int|null $ovh_job_id
 * @property SmsStatus $status
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class SmsLog extends Model
{
    protected $table = 'sms_log';

    protected $fillable = ['order_id', 'phone', 'text', 'ovh_job_id', 'status', 'error', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ovh_job_id' => 'integer',
            'status' => SmsStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
