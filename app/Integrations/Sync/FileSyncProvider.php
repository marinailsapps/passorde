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
 * File mode has nothing to sync or push: its work lives behind the
 * SyncMode::isFile() branches (imports, exports, local stock). Reaching any verb
 * here means a branch was missed.
 */
final class FileSyncProvider implements SyncProvider
{
    public function sync(SyncKind $kind, ?CarbonInterface $since = null, ?CarbonInterface $until = null): SyncOutcome
    {
        throw new SyncFileModeException("Sync {$kind->value} called in file mode.");
    }

    public function pushOrder(Order $order): void
    {
        throw new SyncFileModeException("Push of order {$order->id} called in file mode.");
    }

    public function pushClient(Client $client): void
    {
        throw new SyncFileModeException("Push of client {$client->id} called in file mode.");
    }

    public function retryClientChange(ClientChange $change): void
    {
        throw new SyncFileModeException("Client change {$change->id} sent in file mode.");
    }

    public function pushArticle(ArticleRequest $request): void
    {
        throw new SyncFileModeException("Article request {$request->id} sent in file mode.");
    }

    public function reconcileStock(StockAdjustment $adjustment): void
    {
        throw new SyncFileModeException("Stock adjustment {$adjustment->id} sent in file mode.");
    }

    public function writesEnabled(): bool
    {
        return true;
    }
}
