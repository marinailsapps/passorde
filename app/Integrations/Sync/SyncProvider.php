<?php

namespace App\Integrations\Sync;

use App\Enums\SyncKind;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\Order;
use App\Models\StockAdjustment;
use Carbon\CarbonInterface;

/**
 * Where the app reads its catalogue and directory from and sends its approved
 * orders to. Bound by App\Support\SyncMode: PassordeProvider (Evoliz through the
 * Passorde package) or FileSyncProvider (file mode, a tripwire). Only called from
 * queued jobs and console commands, never from a web request.
 */
interface SyncProvider
{
    /**
     * Runs one kind now and returns what to store on its sync_runs row. Deliveries
     * take a documentdate window (both dates included; default: the last 7 days
     * and a month ahead).
     */
    public function sync(SyncKind $kind, ?CarbonInterface $since = null, ?CarbonInterface $until = null): SyncOutcome;

    /** Creates or finds the BL of an approved order; marks the order sent or failed itself. */
    public function pushOrder(Order $order): void;

    /** A client saved without an order. */
    public function pushClient(Client $client): void;

    /** A client correction Evoliz refused, sent again. */
    public function retryClientChange(ClientChange $change): void;

    /** The order form's "new article": creates the article, updates the article request. */
    public function pushArticle(ArticleRequest $request): void;

    /** A broken entry or gift (or its restore on delete) applied to the remote stock. */
    public function reconcileStock(StockAdjustment $adjustment): void;

    /** False in test mode (PASSORDE_WRITES_ENABLED=false, the amber banner); true in file mode. */
    public function writesEnabled(): bool;
}
