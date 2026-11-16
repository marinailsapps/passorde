<?php

namespace App\Domain\Orders;

use App\Domain\Catalog\LocalStock;
use App\Domain\Catalog\NewArticles;
use App\Domain\Clients\ClientChanges;
use App\Domain\Sms\OrderSms;
use App\Enums\ActivityAction;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Enums\OrderStatus;
use App\Integrations\Ovh\Jobs\SendOrderSmsJob;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\Subject;
use App\Models\ActivityLog;
use App\Models\ArticleRequest;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Order status transitions after the author is done:
 *
 *   pending  → approved (admin) → sent (push succeeded)
 *                               → failed (push failed) → approved (admin retry)
 *   pending  → rejected (admin, with a reason)
 *
 * Every transition runs under a row lock, checks the current status and
 * writes activity_log. Approving and retrying queue the Evoliz push; a sent
 * order queues the client's SMS (SMS_ENABLED).
 *
 * In file mode approved is the end of the road: no push, sent and failed never
 * occur. Approving settles everything locally and queues the SMS (settleLocally).
 * The operator's export then sets exported_at (markExported): that ends the
 * reservation, makes it a sale and lowers the local stock; unmarkExported undoes it.
 * A failed order (its push failed before Evoliz stopped) is settled the same way
 * by "Réessayer" (retry), unless Evoliz may already hold its BL (deliveryMayExist):
 * that one only on the admin's explicit word that Evoliz has no BL for its number.
 * Marking and unmarking the export also work with Evoliz back, for an order approved
 * without it (standalone_at); such an order is never sent (Order::wasEverExported).
 */
class ApprovalService
{
    public function approve(User $admin, Order $order, bool $applyClientChange = true): Order
    {
        // Read once: the licence may lapse between the transaction and the dispatch.
        $file = SyncMode::isFile();

        $order = DB::transaction(function () use ($admin, $order, $applyClientChange, $file) {
            $order = $this->lock($order, [OrderStatus::Pending], __('Only pending orders can be approved.'));
            $order->status = OrderStatus::Approved;
            $order->approved_by = $admin->id;
            $order->approved_at = now();
            $order->save();
            ActivityLog::record(ActivityAction::Approved, $admin, $order, ['number' => $order->number]);

            if ($applyClientChange) {
                ClientChange::query()->where('order_id', $order->id)->where('status', ClientChangeStatus::Pending)->update(['decided_by' => $admin->id]);
            } elseif (ClientChanges::rejectForOrder($order, $admin)) {
                ActivityLog::record(ActivityAction::ClientChangeRejected, $admin, $order, []);
            }

            if ($file) {
                $order->standalone_at = now();
                $order->save();
                $this->settleLocally($admin, $order);
            }

            return $order;
        });

        if (! $file) {
            PushOrderJob::dispatch($order->id);
        } elseif (OrderSms::enabled()) {
            SendOrderSmsJob::dispatch($order->id);
        }

        return $order;
    }

    /**
     * A push queued while Evoliz was active, run after it stopped (licence lapsed,
     * switched off, keys removed): the order is settled as if approved standalone,
     * so it can be exported, instead of failing. A BL already created by an earlier
     * run is never left half done, nor one whose creation was sent without a clear
     * answer (deliveryMayExist): that order fails, for "Réessayer" once Evoliz
     * is back. Never touches an order that is no longer approved or already settled.
     */
    public function settleStandalone(Order $order): void
    {
        $settled = DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null || $order->status !== OrderStatus::Approved || $order->standalone_at !== null || $order->exported_at !== null) {
                return null;
            }

            if (self::deliveryMayExist($order)) {
                $order->status = OrderStatus::Failed;
                $order->evoliz_error = self::mayExistMessage($order);
                $order->save();
                ActivityLog::record(ActivityAction::Failed, null, $order, ['error' => $order->evoliz_error]);

                return null;
            }

            $order->standalone_at = now();
            $order->save();
            ActivityLog::record(ActivityAction::SettledStandalone, null, $order, []);
            $approver = $order->approved_by !== null ? User::query()->find($order->approved_by) : null;
            $this->settleLocally($approver, $order);

            return $order;
        });

        if ($settled !== null && OrderSms::enabled()) {
            SendOrderSmsJob::dispatch($settled->id);
        }
    }

    /**
     * Evoliz may already hold a BL for this order: one is stored, or a creation was
     * sent (passorde_requests) and did not end in a clear refusal (a 4xx other than
     * 408, 409, 429). Such an order is never settled without Evoliz: settling would
     * make it exportable, and a BL made by hand from the export would be a second one.
     */
    public static function deliveryMayExist(Order $order): bool
    {
        return $order->evoliz_delivery_id !== null || DB::table('passorde_requests')
            ->where('subject_type', Subject::ORDER)
            ->where('subject_id', $order->id)
            ->where('kind', 'delivery.create')
            ->where(fn ($query) => $query->where('status', '!=', 'failed')
                ->orWhereNull('http_status')
                ->orWhere('http_status', '<', 400)
                ->orWhere('http_status', '>', 499)
                ->orWhereIn('http_status', [408, 409, 429]))
            ->exists();
    }

    /**
     * Settled for the export on the admin's word that Evoliz holds no BL for it (retry with $checkedEvoliz).
     */
    public static function checkedInEvoliz(Order $order): bool
    {
        return ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::SettledStandalone)
            ->where('meta_json->checked_evoliz', true)->exists();
    }

    public static function downloadedMessage(Carbon $downloaded): string
    {
        return __('This order was in an export file on :date: check in Evoliz that its BL was not typed by hand before sending it.', [
            'date' => $downloaded->copy()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
        ]);
    }

    private static function mayExistMessage(Order $order): string
    {
        return $order->evoliz_delivery_id !== null
            ? __('Evoliz is not available: the BL already created is completed by "Retry" once Evoliz is back.')
            : __('Evoliz may already hold the BL of this order (its creation got no clear answer): it stays failed until Evoliz can be asked, with "Retry" once Evoliz is back.');
    }

    /**
     * Evoliz mode, an order approved while Evoliz was unavailable and never exported:
     * the admin sends it now. Never one exported even once (its BL may have been made
     * by hand from that file, unmarked or not: Order::wasEverExported).
     */
    public function sendToEvoliz(User $admin, Order $order, bool $confirmDownload = false): Order
    {
        $order = DB::transaction(function () use ($admin, $order, $confirmDownload) {
            $order = $this->lock($order, [OrderStatus::Approved], __('Only approved orders can be sent to Evoliz.'));

            if ($order->wasEverExported()) {
                throw new ConflictHttpException(__('This order was exported: it is never sent to Evoliz.'));
            }

            if ($order->standalone_at === null) {
                throw new ConflictHttpException(__('This order is already being sent to Evoliz.'));
            }

            // Settled on the admin's word that Evoliz had no BL (retry with $checkedEvoliz): for the export, never sent.
            if (self::deliveryMayExist($order)) {
                throw new ConflictHttpException(__('Evoliz may already hold the BL of this order: it is never sent again.'));
            }

            // In an export file, never marked exported: its BL may have been typed by hand. Only on explicit confirmation.
            $downloaded = $order->lastDownloadedAt();
            if ($downloaded !== null && ! $confirmDownload) {
                throw new ConflictHttpException(self::downloadedMessage($downloaded));
            }

            $order->standalone_at = null;
            $order->save();
            ActivityLog::record(ActivityAction::SentToEvoliz, $admin, $order, $downloaded !== null ? ['confirmed_download' => true] : []);

            return $order;
        });

        PushOrderJob::dispatch($order->id);

        return $order;
    }

    /**
     * What the push does for an approved order, without Evoliz: the client
     * correction, and a new article's lines catching up with the designation the
     * operator completed in the catalogue.
     */
    private function settleLocally(?User $admin, Order $order): void
    {
        $change = ClientChange::query()->with('client')->where('order_id', $order->id)->where('status', ClientChangeStatus::Pending)->first();

        if ($change !== null) {
            ClientChanges::applyLocally($change);
            ActivityLog::record(ActivityAction::ClientChangeApplied, $admin, $order, ['fields' => ClientChanges::labels(array_keys($change->diff))]);
        }

        $order->load('lines.article');
        $newArticles = ArticleRequest::query()->whereIn('article_id', $order->lines->pluck('article_id')->filter())->pluck('article_id')->flip();

        $order->lines
            ->filter(fn (OrderLine $line) => $line->article !== null && $newArticles->has($line->article_id) && NewArticles::lacksNumbers($line->designation))
            ->each(fn (OrderLine $line) => $line->update(['designation' => $line->article?->designation]));

        // A typed client just became settled: the phones' delta follows updated_at.
        if ($order->client->source === ClientSource::App) {
            $order->client->touch();
        }
    }

    /**
     * The order may be marked or unmarked exported here: in file mode any approved order,
     * with Evoliz running one approved without it (standalone_at, never pushed).
     */
    public static function exportableHere(Order $order): bool
    {
        return SyncMode::isFile() || $order->standalone_at !== null;
    }

    /**
     * The order is in the file the operator took (file mode, or approved during a lapse
     * whatever the mode now). Never one Evoliz may already hold (deliveryMayExist). One
     * transaction, the order's row lock first, then the articles' in id order (LocalStock).
     * The local stock moves only while Evoliz does not run: with Evoliz running, the
     * article stock is Evoliz's (each sync overwrites it), and the BL typed there moves it.
     */
    public function markExported(User $admin, Order $order): Order
    {
        return DB::transaction(function () use ($admin, $order) {
            $order = $this->lock($order, [OrderStatus::Approved], __('Only approved orders can be exported.'));

            if ($order->exported_at !== null) {
                throw new ConflictHttpException(__('This order is already exported.'));
            }

            if (! self::exportableHere($order)) {
                throw new ConflictHttpException(__('This order goes to Evoliz: it is not marked exported.'));
            }

            if (self::deliveryMayExist($order) && ! self::checkedInEvoliz($order)) {
                throw new ConflictHttpException(__('Evoliz may already hold the BL of this order: it is not marked exported.'));
            }

            // While Evoliz runs its stock is Evoliz's (the next sync overwrites ours): no local move then.
            $moves = SyncMode::isFile() ? $this->moveStock($order, -1) : [];
            $order->exported_at = now();
            $order->exported_by = $admin->id;
            $order->save();
            ActivityLog::record(ActivityAction::Exported, $admin, $order, ['number' => $order->number, 'stock' => $moves]);

            return $order;
        });
    }

    /**
     * Back to "to export" (file mode, or an order approved without Evoliz). It still is never
     * sent to Evoliz (Order::wasEverExported). The stock gets back exactly what the export took
     * (the moves recorded with it), and only while Evoliz does not run: an export marked with
     * Evoliz running took nothing, so unmarking it during a lapse gives nothing back.
     */
    public function unmarkExported(User $admin, Order $order): Order
    {
        return DB::transaction(function () use ($admin, $order) {
            $order = $this->lock($order, [OrderStatus::Approved], __('This order is not exported.'));

            if ($order->exported_at === null) {
                throw new ConflictHttpException(__('This order is not exported.'));
            }

            if (! self::exportableHere($order)) {
                throw new ConflictHttpException(__('This order goes to Evoliz: it is not marked exported.'));
            }

            $moves = SyncMode::isFile() ? $this->revertExportMoves($order) : [];
            $order->exported_at = null;
            $order->exported_by = null;
            $order->save();
            ActivityLog::record(ActivityAction::ExportCancelled, $admin, $order, ['number' => $order->number, 'stock' => $moves]);

            return $order;
        });
    }

    /**
     * Undoes the stock moves recorded with the order's last export. An export recorded before
     * the moves were (no "stock" in its entry) took the order's quantities: they come back.
     *
     * @return list<array{article_id: int, before: string, after: string}>
     */
    private function revertExportMoves(Order $order): array
    {
        $meta = ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::Exported)->latest('id')->value('meta_json');

        if (! is_array($meta) || ! array_key_exists('stock', $meta)) {
            return $this->moveStock($order, 1);
        }

        $moves = [];
        foreach ((array) $meta['stock'] as $recorded) {
            $taken = (float) $recorded['before'] - (float) $recorded['after'];
            $move = LocalStock::apply((int) $recorded['article_id'], $taken);

            if ($move !== null) {
                $moves[] = ['article_id' => (int) $recorded['article_id'], ...$move];
            }
        }

        return $moves;
    }

    /**
     * Moves the local stock by the order's quantities, free lines included.
     * Articles without stock management stay as they are.
     *
     * @return list<array{article_id: int, before: string, after: string}>
     */
    private function moveStock(Order $order, int $sign): array
    {
        $moves = [];
        $quantities = OrderLine::query()->where('order_id', $order->id)->whereNotNull('article_id')
            ->groupBy('article_id')->orderBy('article_id')
            ->toBase()->selectRaw('article_id, sum(quantity) as quantity')->get();

        foreach ($quantities as $row) {
            $move = LocalStock::apply((int) $row->article_id, $sign * (float) $row->quantity);

            if ($move !== null) {
                $moves[] = ['article_id' => (int) $row->article_id, ...$move];
            }
        }

        return $moves;
    }

    public function reject(User $admin, Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($admin, $order, $reason) {
            $order = $this->lock($order, [OrderStatus::Pending], __('Only pending orders can be rejected.'));
            $order->status = OrderStatus::Rejected;
            $order->rejected_reason = $reason;
            $order->save();
            ClientChanges::cancelForOrder($order, $admin);
            ActivityLog::record(ActivityAction::Rejected, $admin, $order, ['reason' => $reason]);

            return $order;
        });
    }

    /**
     * "Réessayer". With Evoliz running: back to approved, pushed again. Standalone
     * (Evoliz off or unavailable): settled locally like an approval, so it can be
     * exported, unless Evoliz may already hold its BL (deliveryMayExist). An order
     * whose creation got no clear answer (no BL id stored) is settled only with
     * $checkedEvoliz, the admin's word that Evoliz holds no BL for its number (an
     * installation where Evoliz will not run again); logged, and never sent later.
     */
    public function retry(User $admin, Order $order, bool $checkedEvoliz = false): Order
    {
        $file = SyncMode::isFile();

        $order = DB::transaction(function () use ($admin, $order, $file, $checkedEvoliz) {
            $order = $this->lock($order, [OrderStatus::Failed], __('Only failed orders can be retried.'));

            $checked = false;
            if ($file && self::deliveryMayExist($order)) {
                if (! $checkedEvoliz || $order->evoliz_delivery_id !== null) {
                    throw new ConflictHttpException(self::mayExistMessage($order));
                }
                $checked = true;
            }

            $previousError = $order->evoliz_error;
            $order->status = OrderStatus::Approved;
            $order->evoliz_error = null;
            $order->save();
            ActivityLog::record(ActivityAction::Retried, $admin, $order, ['previous_error' => $previousError]);

            if ($file) {
                $order->standalone_at = now();
                $order->save();
                ActivityLog::record(ActivityAction::SettledStandalone, $admin, $order, $checked ? ['checked_evoliz' => true] : []);
                $this->settleLocally($admin, $order);
            }

            return $order;
        });

        if (! $file) {
            PushOrderJob::dispatch($order->id);
        } elseif (OrderSms::enabled()) {
            SendOrderSmsJob::dispatch($order->id);
        }

        return $order;
    }

    /**
     * Push succeeded. Only an approved order can become sent. Warnings (totals
     * that differ, a contact or a client correction Evoliz refused) keep the order sent and are shown
     * to the admin.
     */
    public function markSent(Order $order, int $deliveryId, string $deliveryNumber, ?string $totalsWarning = null, ?string $contactError = null, ?string $clientChangeError = null): Order
    {
        $order = $this->recordSent($order, $deliveryId, $deliveryNumber, $totalsWarning, $contactError, $clientChangeError);

        if (OrderSms::enabled()) {
            SendOrderSmsJob::dispatch($order->id);
        }

        return $order;
    }

    /**
     * The admin's "Renvoyer": sends the client's SMS again.
     */
    public function resendSms(User $admin, Order $order): void
    {
        if (! OrderSms::enabled() || ! OrderSms::canBeSent($order)) {
            throw new ConflictHttpException(__('SMS can only be sent for sent orders while SMS are enabled.'));
        }

        ActivityLog::record(ActivityAction::SmsResent, $admin, $order, []);
        SendOrderSmsJob::dispatch($order->id, resend: true);
    }

    private function recordSent(Order $order, int $deliveryId, string $deliveryNumber, ?string $totalsWarning, ?string $contactError, ?string $clientChangeError): Order
    {
        return DB::transaction(function () use ($order, $deliveryId, $deliveryNumber, $totalsWarning, $contactError, $clientChangeError) {
            $order = $this->lock($order, [OrderStatus::Approved], 'Only approved orders can be marked sent.');
            $warnings = array_filter([
                $totalsWarning !== null ? __('Totals differ: :detail', ['detail' => $totalsWarning]) : null,
                $contactError !== null ? __('Contact not created in Evoliz: :error', ['error' => $contactError]) : null,
                $clientChangeError !== null ? __('Client details not updated in Evoliz: :error', ['error' => $clientChangeError]) : null,
            ]);
            $order->status = OrderStatus::Sent;
            $order->evoliz_delivery_id = $deliveryId;
            $order->evoliz_delivery_number = $deliveryNumber;
            $order->evoliz_error = null;
            $order->evoliz_warning = $warnings === [] ? null : implode("\n", $warnings);
            $order->sent_at = now();
            $order->save();
            ActivityLog::record(ActivityAction::Sent, null, $order, ['delivery_number' => $deliveryNumber, 'delivery_id' => $deliveryId]);

            if ($totalsWarning !== null) {
                ActivityLog::record(ActivityAction::TotalsMismatch, null, $order, ['warning' => $totalsWarning]);
            }

            if ($contactError !== null) {
                ActivityLog::record(ActivityAction::ContactFailed, null, $order, ['error' => $contactError]);
            }

            return $order;
        });
    }

    /**
     * Push failed; the admin sees the message and may retry.
     */
    public function markFailed(Order $order, string $error): Order
    {
        return DB::transaction(function () use ($order, $error) {
            $order = $this->lock($order, [OrderStatus::Approved], 'Only approved orders can be marked failed.');
            $order->status = OrderStatus::Failed;
            $order->evoliz_error = $error;
            $order->save();
            ActivityLog::record(ActivityAction::Failed, null, $order, ['error' => $error]);

            return $order;
        });
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function lock(Order $order, array $allowed, string $message): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $allowed, true)) {
            throw new ConflictHttpException($message);
        }

        return $locked;
    }
}
