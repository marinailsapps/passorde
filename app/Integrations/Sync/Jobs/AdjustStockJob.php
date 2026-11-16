<?php

namespace App\Integrations\Sync\Jobs;

use App\Enums\EvolizStockStatus;
use App\Integrations\Sync\SyncLicence;
use App\Integrations\Sync\SyncProvider;
use App\Models\StockAdjustment;
use App\Support\SyncMode;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Applies a broken entry / gift outside an order to the Evoliz stock, or adds it
 * back once the entry is deleted (SyncProvider::reconcileStock). Dispatch it
 * after any change: it compares what the entry wants with what Evoliz has and
 * does only the difference. One queued job per entry, and never two running at
 * once for the same entry, so a deletion during the deduction waits and then
 * adds back.
 */
class AdjustStockJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Releases while another job of the same entry runs count as attempts. */
    public int $tries = 20;

    public int $timeout = 120;

    public function __construct(public int $adjustmentId) {}

    public function uniqueId(): string
    {
        return 'stock-adjustment-'.$this->adjustmentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('stock-adjustment-'.$this->adjustmentId))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(SyncProvider $provider): void
    {
        $adjustment = StockAdjustment::withTrashed()->find($this->adjustmentId);

        if ($adjustment === null) {
            return;
        }

        // Evoliz stopped since: not sent, "Réessayer" once it is back (admins only).
        if (SyncMode::isFile()) {
            $adjustment->update(['evoliz_status' => EvolizStockStatus::Failed, 'evoliz_error' => SyncLicence::unavailable()]);

            return;
        }

        $provider->reconcileStock($adjustment);
    }

    public function failed(?Throwable $exception): void
    {
        $adjustment = StockAdjustment::withTrashed()->find($this->adjustmentId);

        if ($adjustment !== null && $adjustment->evoliz_status === EvolizStockStatus::Pending) {
            $adjustment->update([
                'evoliz_status' => EvolizStockStatus::Failed,
                'evoliz_error' => __('The stock job stopped: :message', ['message' => $exception?->getMessage() ?? '?']),
            ]);
        }
    }
}
