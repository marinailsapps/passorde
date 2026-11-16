<?php

namespace App\Domain\Ops;

use App\Models\OpsHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued every minute by ops:heartbeat; when the worker runs it, the queue is alive.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        OpsHeartbeat::beat(OpsHeartbeat::QUEUE);
    }
}
