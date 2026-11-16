<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Evoliz request (an order or client push, a stock adjustment, a new article)
 * with payload and response, kept for installations that already have rows in this
 * table. Never written: saving or deleting a row throws; every request is logged in
 * passorde_requests (with the order, client, article request or stock entry as
 * subject). Read by two lookups only: a contact already created
 * (OrderPush::contactSent) and an uncertain stock PATCH (StockPush::lastPatchWasUncertain).
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $stock_adjustment_id
 * @property int|null $client_id
 * @property int|null $article_request_id
 * @property string $kind
 * @property string $method
 * @property string $path
 * @property array<mixed>|null $payload_json
 * @property int|null $http_status
 * @property array<mixed>|null $response_json
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 */
class EvolizJob extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = fn (): never => throw new LogicException('evoliz_jobs is read-only: Evoliz requests are logged in passorde_requests.');

        static::saving($refuse);
        static::deleting($refuse);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'response_json' => 'array',
            'http_status' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class)->withTrashed();
    }
}
