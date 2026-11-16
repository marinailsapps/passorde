<?php

namespace App\Console\Commands;

use App\Domain\Ops\QueueHeartbeatJob;
use App\Models\OpsHeartbeat;
use Illuminate\Console\Command;

/**
 * Scheduled every minute: the scheduler records that it runs and queues a job
 * that records that the queue worker runs (read by ops:check).
 */
class OpsHeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record the scheduler heartbeat and queue the queue-worker heartbeat';

    public function handle(): int
    {
        OpsHeartbeat::beat(OpsHeartbeat::SCHEDULER);
        QueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
