<?php

namespace App\Integrations\Sync\Jobs;

use App\Domain\Orders\ApprovalService;
use App\Enums\OrderStatus;
use App\Integrations\Sync\SyncProvider;
use App\Models\Order;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates the Evoliz delivery note of an approved order (SyncProvider::pushOrder).
 *
 * Unique per order, so a double click never runs it twice. One attempt at the
 * queue level: transient Evoliz errors are retried inside the push, anything
 * else becomes "failed" and the admin retries explicitly. In test mode
 * (PASSORDE_WRITES_ENABLED=false) the order fails with "Envoi Evoliz désactivé
 * (mode test)" before any write reaches Evoliz.
 */
class PushOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $orderId) {}

    public function uniqueId(): string
    {
        return 'order-'.$this->orderId;
    }

    public function handle(SyncProvider $provider): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        // Evoliz stopped since the approval: the order becomes exportable instead of failing.
        if (SyncMode::isFile()) {
            app(ApprovalService::class)->settleStandalone($order);

            return;
        }

        $provider->pushOrder($order);
    }

    /**
     * The worker gave up (timeout, crash): never leave the order stuck in "approved".
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order !== null && $order->status === OrderStatus::Approved) {
            app(ApprovalService::class)->markFailed($order, __('The push job stopped: :message', [
                'message' => $exception?->getMessage() ?? '?',
            ]));
        }
    }
}
