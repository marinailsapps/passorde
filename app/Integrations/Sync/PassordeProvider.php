<?php

namespace App\Integrations\Sync;

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Integrations\Sync\Push\OrderPush;
use App\Integrations\Sync\Push\StockPush;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\EvolizUser;
use App\Models\Order;
use App\Models\StockAdjustment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Passorde\Sync\SyncArticles;
use Passorde\Sync\SyncClients;
use Passorde\Sync\SyncDeliveries;
use Passorde\Sync\SyncPaytypes;
use Passorde\Sync\SyncUsers;
use Passorde\Sync\Window;

/**
 * Evoliz through the Passorde package. Reads run Passorde's runners, which hand
 * the records to the app's own sinks (App\Integrations\Sync\Sinks, bound in
 * AppServiceProvider), so the app's tables keep their shape and rules.
 *
 * Writes run Passorde's verbs (CreateClient, UpsertContact, UpdateClient,
 * CreateArticle, CreateDelivery, AdjustStock) from OrderPush and StockPush,
 * which keep the app's choices and French messages.
 */
final class PassordeProvider implements SyncProvider
{
    /** The scheduled daytime run and the default window: the last 7 days. */
    public const DEFAULT_DAYS = 7;

    /** BLs are sometimes dated ahead; the window always reaches this far forward. */
    public const AHEAD_DAYS = 31;

    private const TIMEZONE = 'Europe/Paris';

    public function sync(SyncKind $kind, ?CarbonInterface $since = null, ?CarbonInterface $until = null): SyncOutcome
    {
        return match ($kind) {
            SyncKind::Articles => SyncOutcome::of([app(SyncArticles::class)->run()]),
            SyncKind::Clients => SyncOutcome::of([app(SyncClients::class)->run()]),
            SyncKind::Paytypes => SyncOutcome::of([app(SyncPaytypes::class)->run()]),
            SyncKind::Deliveries => $this->deliveries($since, $until),
        };
    }

    /**
     * The documentdate window of a delivery sync, Paris dates.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function deliveryWindow(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $today = Carbon::today(self::TIMEZONE);

        return [
            $since !== null ? Carbon::parse($since->toDateString(), self::TIMEZONE) : $today->copy()->subDays(self::DEFAULT_DAYS),
            $until !== null ? Carbon::parse($until->toDateString(), self::TIMEZONE) : $today->copy()->addDays(self::AHEAD_DAYS),
        ];
    }

    private function deliveries(?CarbonInterface $since, ?CarbonInterface $until): SyncOutcome
    {
        [$from, $to] = self::deliveryWindow($since, $until);

        $outcome = SyncOutcome::of(app(SyncDeliveries::class)->run(new Window(
            CarbonImmutable::parse($from->toDateString(), self::TIMEZONE),
            CarbonImmutable::parse($to->toDateString(), self::TIMEZONE),
        )));

        if ($outcome->status === SyncStatus::Succeeded) {
            $this->nameUsers();
        }

        return $outcome;
    }

    /**
     * Labels of the Evoliz users seen on BLs, from GET /users through UserSink.
     * Best effort: a key without that scope keeps the users unnamed, and its
     * failure stays in passorde_runs, not on the deliveries run.
     */
    private function nameUsers(): void
    {
        if (EvolizUser::query()->whereNull('label')->exists()) {
            app(SyncUsers::class)->run();
        }
    }

    public function pushOrder(Order $order): void
    {
        app(OrderPush::class)->push($order->id);
    }

    public function pushClient(Client $client): void
    {
        app(OrderPush::class)->pushClient($client->id);
    }

    public function retryClientChange(ClientChange $change): void
    {
        app(OrderPush::class)->retryClientChange($change->id);
    }

    public function pushArticle(ArticleRequest $request): void
    {
        app(OrderPush::class)->pushArticle($request->id);
    }

    public function reconcileStock(StockAdjustment $adjustment): void
    {
        app(StockPush::class)->reconcile($adjustment->id);
    }

    public function writesEnabled(): bool
    {
        return (bool) config('passorde-connect.writes_enabled');
    }
}
