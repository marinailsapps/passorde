<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RefusesInFileMode;
use App\Enums\EvolizStockStatus;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Models\StockAdjustment;
use Illuminate\Console\Command;

/**
 * Queues the Evoliz stock update of every broken entry / gift outside an order
 * that Evoliz does not reflect yet: the ones recorded in test mode, or failed.
 * Run once after setting PASSORDE_WRITES_ENABLED=true (docs/RUNBOOK.md).
 */
class EvolizPushStockAdjustmentsCommand extends Command
{
    use RefusesInFileMode;

    protected $signature = 'evoliz:push-stock-adjustments {--dry-run : List them without queuing anything}';

    protected $description = 'Send to Evoliz the broken entries and gifts recorded in test mode or failed';

    public function handle(): int
    {
        if ($this->refusedInFileMode()) {
            return self::FAILURE;
        }

        $entries = StockAdjustment::withTrashed()->with('article')->orderBy('id')->get()
            ->filter(fn (StockAdjustment $entry) => $entry->evolizOutOfStep() && $entry->evoliz_status !== EvolizStockStatus::Skipped);

        foreach ($entries as $entry) {
            $this->line(sprintf('#%d %s %s × %s (%s%s)', $entry->id, $entry->happened_on->toDateString(), $entry->article->reference, $entry->quantity,
                $entry->evoliz_status->value ?? '-', $entry->trashed() ? ', deleted: give back' : ''));

            if (! $this->option('dry-run')) {
                $entry->update(['evoliz_status' => EvolizStockStatus::Pending, 'evoliz_error' => null]);
                AdjustStockJob::dispatch($entry->id);
            }
        }

        $this->info($this->option('dry-run') ? "{$entries->count()} to send (dry run, nothing queued)." : "{$entries->count()} queued.");

        return self::SUCCESS;
    }
}
