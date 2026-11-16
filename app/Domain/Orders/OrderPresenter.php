<?php

namespace App\Domain\Orders;

use App\Domain\Catalog\NewArticles;
use App\Domain\Clients\ClientChanges;
use App\Domain\Sms\OrderSms;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Http\Api\ApiAliases;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\SyncMode;

/**
 * JSON shapes of orders shared by the API (docs/API.md) and the Inertia pages.
 */
class OrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order, User $viewer): array
    {
        return ApiAliases::add([
            'uuid' => $order->uuid,
            'number' => $order->number,
            'status' => $order->status->value,
            'display_status' => self::displayStatus($order, $viewer)->value,
            'status_label' => self::statusLabel($order, $viewer),
            'author' => ['id' => $order->user->id, 'name' => $order->user->name],
            'created_at' => $order->created_at->toIso8601String(),
            'client' => [
                'id' => $order->client->id,
                'company_name' => $order->client->company_name,
                'town' => $order->client->town,
            ],
            'total_quantity' => $order->total_quantity,
            'total_ttc' => $order->total_ttc,
            'evoliz_delivery_number' => $order->evoliz_delivery_number,
            'exported_at' => $order->exported_at?->toIso8601String(),
            'can_edit' => $order->isEditableBy($viewer),
            'can_delete' => $order->isDeletableBy($viewer),
            'can_approve' => $viewer->isAdmin() && $order->status === OrderStatus::Pending,
        ], ApiAliases::summary());
    }

    /**
     * @param  array<int, array{is_stocked: bool, stock_qty: string|null, reserved_qty: string, unit_price_ht: string}>|null  $stock  from stockMap(), when presenting many orders
     * @param  list<array<string, mixed>>|null  $conflicts  from StockConflictFinder (admins), when presenting many orders
     * @return array<string, mixed>
     */
    public static function detail(Order $order, User $viewer, ?array $stock = null, ?array $conflicts = null): array
    {
        $order->loadMissing(['client', 'user', 'paytype', 'approver', 'exporter', 'lines.article', 'clientChange.decider']);
        $admin = $viewer->isAdmin();
        $totals = OrderTotals::compute($order->lines->map(fn (OrderLine $line) => $line->only(['quantity', 'unit_price_ht', 'vat_rate'])));
        $stock ??= self::stockMap($order->lines->pluck('article_id')->filter()->all());

        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'status' => $order->status->value,
            'display_status' => self::displayStatus($order, $viewer)->value,
            'status_label' => self::statusLabel($order, $viewer),
            'created_at' => $order->created_at->toIso8601String(),
            'updated_at' => $order->updated_at->toIso8601String(),
            'approved_at' => $order->approved_at?->toIso8601String(),
            'approved_by' => $order->approver?->name,
            'sent_at' => $order->sent_at?->toIso8601String(),
            'exported_at' => $order->exported_at?->toIso8601String(),
            'exported_by' => $order->exporter?->name,
            'author' => ['id' => $order->user->id, 'name' => $order->user->name],
            'client' => self::client($order->client),
            'client_change' => $order->clientChange !== null ? self::clientChange($order->clientChange) : null,
            'paytype' => $order->paytype ? ['id' => $order->paytype->id, 'label' => $order->paytype->label] : null,
            'comment' => $order->comment,
            'lines' => $order->lines->map(fn (OrderLine $line) => self::line($line, $stock))->all(),
            'totals' => ApiAliases::add([
                'quantity' => $order->total_quantity,
                'ht' => $order->total_ht,
                'vat' => $order->total_vat,
                'ttc' => $order->total_ttc,
                'vat_by_rate' => $totals->vatBreakdown(),
            ], ApiAliases::totals()),
            'rejected_reason' => $order->rejected_reason,
            'evoliz_delivery_number' => $order->evoliz_delivery_number,
            'can_edit' => $order->isEditableBy($viewer),
            'can_delete' => $order->isDeletableBy($viewer),
            // Admin-only: push errors and the timeline are not the sales person's business.
            'evoliz_error' => $admin ? $order->evoliz_error : null,
            // Admin-only: the common push errors in plain French (EvolizErrorSummary), null otherwise.
            'evoliz_error_summary' => $admin ? EvolizErrorSummary::for($order->evoliz_error) : null,
            'evoliz_warning' => $admin ? $order->evoliz_warning : null,
            'activity' => $admin ? self::activity($order) : null,
            'can_approve' => $admin && $order->status === OrderStatus::Pending,
            'can_reject' => $admin && $order->status === OrderStatus::Pending,
            // Standalone, "Réessayer" settles it for the export, unless Evoliz may hold its BL already.
            'can_retry' => $admin && $order->status === OrderStatus::Failed && (! SyncMode::isFile() || ! ApprovalService::deliveryMayExist($order)),
            'can_unmark_exported' => $admin && $order->exported_at !== null && ApprovalService::exportableHere($order),
            // Evoliz back, an order approved during the lapse and not exported (e.g. unmarked since): mark it again.
            'can_mark_exported' => $admin && $order->status === OrderStatus::Approved && $order->exported_at === null && $order->standalone_at !== null
                && ! SyncMode::isFile() && (! ApprovalService::deliveryMayExist($order) || ApprovalService::checkedInEvoliz($order)),
            // Standalone, a failed order whose BL creation got no clear answer: settled for the export on the admin's word.
            'can_settle_checked' => $admin && $order->status === OrderStatus::Failed && SyncMode::isFile() && $order->evoliz_delivery_id === null
                && ApprovalService::deliveryMayExist($order),
            // Evoliz mode, approved while Evoliz was unavailable and not exported: sent only on the admin's word.
            // The last time the order was in a downloaded export file: "Envoyer à Evoliz" then asks for a confirmation.
            'export_downloaded_at' => $admin ? $order->lastDownloadedAt()?->toIso8601String() : null,
            // Admins: a push to Evoliz is really under way (queued or retrying). Not for an order approved without Evoliz
            // (standalone_at, until "Envoyer à Evoliz"), nor one ever exported by hand (never sent).
            'push_pending' => $admin && $order->status === OrderStatus::Approved && $order->standalone_at === null && ! SyncMode::isFile() && ! $order->wasEverExported(),
            'can_send_to_evoliz' => $admin && $order->status === OrderStatus::Approved && $order->standalone_at !== null && ! SyncMode::isFile() && ! $order->wasEverExported() && ! ApprovalService::deliveryMayExist($order),
            // Admin-only: articles this order would take below zero (StockConflictFinder).
            'stock_conflicts' => $admin ? ($conflicts ?? app(StockConflictFinder::class)->forOrder($order->id)) : null,
            // Admin-only, while pending: articles added from an order whose completed designation we have not seen yet.
            'articles_without_numbers' => $admin && $order->status === OrderStatus::Pending ? self::articlesWithoutNumbers($order) : [],
            // Admin-only, null while SMS_ENABLED is false: the client's SMS (OrderSms::present).
            'sms' => $admin ? OrderSms::present($order) : null,
        ];
    }

    /**
     * @return list<string> References, from the catalogue as of its last sync (every 15 minutes)
     */
    private static function articlesWithoutNumbers(Order $order): array
    {
        $new = ArticleRequest::query()->whereIn('article_id', $order->lines->pluck('article_id')->filter())->pluck('article_id')->flip();

        return $order->lines
            ->filter(fn (OrderLine $line) => $line->article !== null && $new->has($line->article_id) && NewArticles::lacksNumbers($line->article->designation))
            ->map(fn (OrderLine $line) => (string) $line->article?->reference)
            ->unique()->values()->all();
    }

    /**
     * Admins see the real status; authors see a failed push as "approved".
     */
    public static function displayStatus(Order $order, User $viewer): OrderStatus
    {
        return $viewer->isAdmin() ? $order->status : $order->status->forAuthor();
    }

    public static function statusLabel(Order $order, User $viewer): string
    {
        return match (self::displayStatus($order, $viewer)) {
            OrderStatus::Pending => __('order.status.pending'),
            OrderStatus::Approved => match (true) {
                $order->exported_at !== null => __('order.label.exported'),
                SyncMode::isFile() => __('order.status.approved'),
                $order->standalone_at !== null => __('order.label.standalone'),
                default => __('order.label.approved'),
            },
            OrderStatus::Failed => __('order.label.failed'),
            OrderStatus::Sent => $order->evoliz_delivery_number !== null
                ? __('order.label.sent', ['number' => $order->evoliz_delivery_number])
                : __('order.status.sent'),
            OrderStatus::Rejected => __('order.status.rejected'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function activity(Order $order): array
    {
        return $order->activity()->with('user')->get()->map(fn (ActivityLog $entry) => [
            'action' => $entry->action->value,
            'label' => __('activity.'.$entry->action->value),
            'user' => $entry->user?->name,
            'created_at' => $entry->created_at->toIso8601String(),
            'detail' => match ($entry->action) {
                ActivityAction::Rejected => $entry->meta_json['reason'] ?? null,
                ActivityAction::Failed => $entry->meta_json['error'] ?? null,
                ActivityAction::Sent => $entry->meta_json['delivery_number'] ?? null,
                ActivityAction::Retried => $entry->meta_json['previous_error'] ?? null,
                ActivityAction::TotalsMismatch => $entry->meta_json['warning'] ?? null,
                ActivityAction::ContactFailed => $entry->meta_json['error'] ?? null,
                ActivityAction::ClientChangeFailed => $entry->meta_json['error'] ?? null,
                ActivityAction::ClientChangeApplied => $entry->meta_json['fields'] ?? null,
                ActivityAction::Duplicated => $entry->meta_json['from_number'] ?? null,
                ActivityAction::Updated => OrderChanges::detail($entry->meta_json),
                // Settled on the admin's word that Evoliz held no BL for it (ApprovalService::retry): who and when are the entry's.
                ActivityAction::SettledStandalone => ($entry->meta_json['checked_evoliz'] ?? false) === true ? __('Checked in Evoliz: no delivery note for this order') : null,
                default => null,
            },
        ])->values()->all();
    }

    /**
     * Current stock and reservations of the given articles, for line cards.
     *
     * @param  array<int, int|null>  $articleIds
     * @return array<int, array{is_stocked: bool, stock_qty: string|null, reserved_qty: string, unit_price_ht: string}>
     */
    public static function stockMap(array $articleIds): array
    {
        return Article::query()->withReserved()->whereIn('articles.id', array_unique($articleIds))->get()
            ->mapWithKeys(fn (Article $article) => [$article->id => [
                'is_stocked' => $article->is_stocked,
                'stock_qty' => $article->stock_qty,
                'reserved_qty' => number_format((float) ($article->getAttribute('reserved_qty') ?? 0), 2, '.', ''),
                'unit_price_ht' => $article->unit_price_ht,
            ]])->all();
    }

    /**
     * @param  array<int, array{is_stocked: bool, stock_qty: string|null, reserved_qty: string, unit_price_ht: string}>  $stock  from stockMap()
     * @return array<string, mixed>
     */
    public static function line(OrderLine $line, array $stock = []): array
    {
        $stockRow = $line->article_id !== null ? ($stock[$line->article_id] ?? null) : null;

        return [
            'position' => $line->position,
            'article_id' => $line->article_id,
            'reference' => $line->article?->reference,
            'designation' => $line->designation,
            'note' => $line->note,
            'quantity' => $line->quantity,
            'unit_price_ht' => $line->unit_price_ht,
            'vat_rate' => $line->vat_rate,
            'is_free' => $line->is_free,
            'total_ht' => $line->total_ht,
            'stock' => $stockRow === null ? null : [
                'is_stocked' => $stockRow['is_stocked'],
                'stock_qty' => $stockRow['stock_qty'],
                'reserved_qty' => $stockRow['reserved_qty'],
            ],
            // Today's catalogue price, so a copied (negotiated) price can be compared.
            'catalog_unit_price_ht' => $stockRow['unit_price_ht'] ?? null,
        ];
    }

    /**
     * The order's client correction; its fields also under their old names (ApiAliases), as every client field of the API.
     *
     * @return array<string, mixed>
     */
    private static function clientChange(ClientChange $change): array
    {
        $present = ClientChanges::present($change);

        return [...$present, 'changes' => ApiAliases::add((array) $present['changes'], ApiAliases::client())];
    }

    /**
     * @return array<string, mixed>
     */
    public static function client(Client $client): array
    {
        return ApiAliases::add([
            'id' => $client->id,
            'uuid' => $client->uuid,
            'company_name' => $client->company_name,
            'contact_name' => $client->contact_name,
            'address_line' => $client->address_line,
            'postcode' => $client->postcode,
            'town' => $client->town,
            'phone' => $client->phone,
            'mobile' => $client->mobile,
            'email' => $client->email,
            'extra_field' => $client->extra_field,
            'linked' => $client->isSettled(),
        ], ApiAliases::client());
    }
}
