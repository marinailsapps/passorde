<?php

namespace App\Integrations\Sync\Push;

use App\Enums\ActivityAction;
use App\Enums\EvolizStockStatus;
use App\Integrations\Sync\SyncLicence;
use App\Models\ActivityLog;
use App\Models\EvolizJob;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\WritesDisabledException;
use Passorde\Drafts\StockExpectation;
use Passorde\Licence\Exceptions\LicenceException;
use Passorde\Writes\AdjustStock;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;
use Passorde\Writes\Results\StockAdjusted;
use Throwable;

/**
 * Brings the Evoliz stock of an article in step with a broken entry / gift
 * outside an order (the Evoliz connector notes in Passorde Connect "Stock adjustment"), through Passorde's
 * AdjustStock: GET, PATCH stocked_quantity (current − quantity when the entry
 * is recorded, + quantity once it is deleted), GET again. A BL that moved the
 * stock in between is reported in the activity log.
 *
 * Evoliz keeps no key to recognise an adjustment by, so the stock read and the
 * value written are stored on the entry (evoliz_before / evoliz_after): when the
 * last PATCH of the entry got no answer or a 5xx, the retry gives them back as a
 * StockExpectation, and a stock already showing that value is not adjusted a
 * second time. Requests land in passorde_requests with the entry as subject.
 * Runs only from AdjustStockJob, one at a time per entry.
 */
final class StockPush
{
    public function reconcile(int $adjustmentId): void
    {
        $adjustment = StockAdjustment::withTrashed()->with('article')->find($adjustmentId);

        if ($adjustment === null || ! $adjustment->evolizOutOfStep()) {
            return;
        }

        if (! config('passorde-connect.writes_enabled')) {
            $this->mark($adjustment, EvolizStockStatus::Disabled, PushMessages::testMode());

            return;
        }

        $refusal = SyncLicence::refusal(SyncLicence::status());

        if ($refusal !== null) {
            $this->mark($adjustment, EvolizStockStatus::Failed, $refusal);

            return;
        }

        try {
            Subject::run(Subject::STOCK_ADJUSTMENT, $adjustment->id, fn () => $this->adjust($adjustment));
        } catch (LicenceException $e) {
            $this->mark($adjustment, EvolizStockStatus::Failed, (string) SyncLicence::refusal($e->status));
        } catch (WritesDisabledException) {
            $this->mark($adjustment, EvolizStockStatus::Disabled, PushMessages::testMode());
        } catch (ApiException $e) {
            $this->mark($adjustment, EvolizStockStatus::Failed, PushMessages::ofException($e));
        } catch (Throwable $e) {
            report($e);
            $this->mark($adjustment, EvolizStockStatus::Failed, PushMessages::unexpected($e));
        }
    }

    private function adjust(StockAdjustment $adjustment): void
    {
        $restore = $adjustment->trashed();
        $quantity = (float) $adjustment->quantity;
        $delta = $restore ? $quantity : -$quantity;
        $evolizId = (int) $adjustment->article->evoliz_article_id;

        $result = app(AdjustStock::class)->run($evolizId, $delta, $this->expectation($adjustment, $delta));

        if ($result instanceof StockAdjusted) {
            $this->done($adjustment, $restore, $result);

            return;
        }

        if ($result instanceof Refused && $result->status === null && array_key_exists('stock_management', $result->fieldErrors)) {
            $this->mark($adjustment, EvolizStockStatus::Skipped, __('This article has no stock management in Evoliz: nothing to adjust.'));

            return;
        }

        if ($result instanceof Failed && $result->before !== null && $result->written !== null) {
            // What was attempted, for the retry to recognise itself.
            $adjustment->update(['evoliz_before' => $result->before, 'evoliz_after' => $result->written]);
        }

        $this->mark($adjustment, EvolizStockStatus::Failed, PushMessages::of($result));
    }

    /**
     * The attempt to give back to AdjustStock: only when the last PATCH of this
     * entry may have gone through, and the stored values are this adjustment's.
     */
    private function expectation(StockAdjustment $adjustment, float $delta): ?StockExpectation
    {
        if ($adjustment->evoliz_before === null || $adjustment->evoliz_after === null
            || ! self::same((float) $adjustment->evoliz_after - (float) $adjustment->evoliz_before, $delta)
            || ! $this->lastPatchWasUncertain($adjustment)) {
            return null;
        }

        return new StockExpectation((string) $adjustment->evoliz_before, (string) $adjustment->evoliz_after);
    }

    /**
     * The last PATCH of this entry failed without an answer or with a 5xx:
     * Evoliz may have applied it. Logged in passorde_requests, or in the read-only
     * evoliz_jobs.
     */
    private function lastPatchWasUncertain(StockAdjustment $adjustment): bool
    {
        $last = DB::table('passorde_requests')
            ->where('subject_type', Subject::STOCK_ADJUSTMENT)
            ->where('subject_id', $adjustment->id)
            ->where('method', 'PATCH')
            ->latest('id')
            ->first(['status', 'http_status']);

        $last ??= $adjustment->evolizJobs()->where('method', 'PATCH')->latest('id')->first(['status', 'http_status']);

        return $last !== null && $last->status === EvolizJob::FAILED && ($last->http_status === null || $last->http_status >= 500);
    }

    private function done(StockAdjustment $adjustment, bool $restore, StockAdjusted $result): void
    {
        $before = (float) $result->before;
        $target = (float) $result->written;
        $now = $result->after === null ? $target : (float) $result->after;
        $warning = null;

        if (! self::same($now, $target)) {
            $warning = __('Evoliz stock after the adjustment: :now (expected :target). Something else moved the stock at the same time: check it in Evoliz.', [
                'now' => self::number($now), 'target' => self::number($target),
            ]);
            ActivityLog::record(ActivityAction::EvolizStockMismatch, null, null, [
                'adjustment_id' => $adjustment->id,
                'reference' => $adjustment->article->reference,
                'before' => $before,
                'expected' => $target,
                'actual' => $now,
            ]);
            Log::warning('Evoliz stock differs after an adjustment', ['adjustment' => $adjustment->id, 'expected' => $target, 'actual' => $now]);
        }

        $adjustment->update([
            'evoliz_status' => EvolizStockStatus::Sent,
            'evoliz_error' => $warning,
            'evoliz_before' => $before,
            'evoliz_after' => $now,
            'evoliz_applied' => ! $restore,
        ]);
        // The report reads the local stock: keep it equal to Evoliz's until the next sync.
        $adjustment->article->update(['stock_qty' => $now]);
    }

    private function mark(StockAdjustment $adjustment, EvolizStockStatus $status, string $error): void
    {
        $adjustment->update(['evoliz_status' => $status, 'evoliz_error' => $error]);

        if ($status === EvolizStockStatus::Failed) {
            Log::warning('Evoliz stock adjustment failed', ['adjustment' => $adjustment->id, 'error' => $error]);
        }
    }

    private static function same(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
