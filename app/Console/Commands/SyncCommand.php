<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RefusesInFileMode;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\SyncProvider;
use App\Models\SyncRun;
use Illuminate\Console\Command;

/**
 * Shared implementation of the sync:articles|clients|paytypes commands. Runs
 * inline by default (used by the scheduler) or queues the job with --queue.
 */
abstract class SyncCommand extends Command
{
    use RefusesInFileMode;

    abstract protected function kind(): SyncKind;

    public function handle(): int
    {
        if ($this->refusedInFileMode()) {
            return self::FAILURE;
        }

        $trigger = SyncTrigger::tryFrom((string) $this->option('trigger')) ?? SyncTrigger::Console;
        $run = SyncRun::queue($this->kind(), $trigger);

        if ($this->option('queue')) {
            SyncJob::dispatchFor($run);
            $this->info("Queued sync run #{$run->id}.");

            return self::SUCCESS;
        }

        $run = SyncJob::perform(app(SyncProvider::class), $run);

        $this->line(sprintf(
            'Sync run #%d %s: %d seen, %d changed%s',
            $run->id,
            $run->status->value,
            $run->rows_seen,
            $run->rows_changed,
            $run->error ? " — {$run->error}" : '',
        ));

        return $run->status === SyncStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
