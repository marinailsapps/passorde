<?php

use App\Domain\Orders\ApprovalService;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\OrderPush;
use App\Integrations\Sync\Push\Subject;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Support\SyncMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Passorde\Testing\TestLicence;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
| an order Evoliz may already hold is never made
| exportable, an order exported once is never sent, a failed order is not a dead
| end while standalone, and approve() decides on one reading of the mode.
*/

beforeEach(function () {
    travelTo('2026-11-13 09:00:00');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->variety = Article::factory()->create(['stock_qty' => 10, 'is_stocked' => true]);
    $this->order = Order::factory()->for(Client::factory())->status(OrderStatus::Pending)->withLines([[$this->variety, 2]])->create();
});

/** A delivery creation Passorde logged for the order. */
function deliveryCreate(Order $order, string $status, ?int $httpStatus): void
{
    DB::table('passorde_requests')->insert([
        'kind' => 'delivery.create', 'method' => 'POST', 'path' => '/deliveries', 'status' => $status, 'http_status' => $httpStatus,
        'attempts' => 1, 'subject_type' => Subject::ORDER, 'subject_id' => $order->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Approved in Evoliz mode, then its push failed. */
function failedOrder(Order $order, User $admin): Order
{
    app(ApprovalService::class)->approve($admin, $order);

    return app(ApprovalService::class)->markFailed($order->fresh(), 'Evoliz a répondu 503');
}

describe('S1: a creation without a clear answer', function () {
    it('keeps an order failed when a creation was sent and its outcome is unknown', function (string $status, ?int $http) {
        app(ApprovalService::class)->approve($this->admin, $this->order);
        deliveryCreate($this->order, $status, $http);

        licenceExpired();
        app()->call([new PushOrderJob($this->order->id), 'handle']);

        expect($this->order->fresh())->status->toBe(OrderStatus::Failed)->standalone_at->toBeNull()
            ->evoliz_error->toContain('Evoliz a peut-être déjà le BL')
            ->and(Order::query()->toExport()->whereKey($this->order->id)->exists())->toBeFalse();
        Http::assertNothingSent();
    })->with([
        'still running (worker died)' => ['running', null],
        'timed out, no status' => ['failed', null],
        'server error' => ['failed', 503],
        'rate limited' => ['failed', 429],
        'request timeout' => ['failed', 408],
        'succeeded, order not stored' => ['succeeded', 201],
    ]);

    it('settles an order whose creation Evoliz clearly refused', function () {
        app(ApprovalService::class)->approve($this->admin, $this->order);
        deliveryCreate($this->order, 'failed', 422);

        licenceExpired();
        app()->call([new PushOrderJob($this->order->id), 'handle']);

        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->not->toBeNull();
    });

    it('looks only at delivery creations of this order', function () {
        $other = Order::factory()->for(Client::factory())->status(OrderStatus::Pending)->withLines([[$this->variety, 1]])->create();
        deliveryCreate($other, 'running', null);
        DB::table('passorde_requests')->insert([
            'kind' => 'delivery.lookup', 'method' => 'GET', 'path' => '/deliveries', 'status' => 'failed', 'http_status' => 503,
            'attempts' => 1, 'subject_type' => Subject::ORDER, 'subject_id' => $this->order->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(ApprovalService::deliveryMayExist($this->order))->toBeFalse()
            ->and(ApprovalService::deliveryMayExist($other))->toBeTrue();
    });
});

describe('S5: a failed order while standalone', function () {
    it('settles a failed order for the export with "Valider hors Evoliz"', function () {
        failedOrder($this->order, $this->admin);
        licenceExpired();
        actingAs($this->admin);

        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_retry', true));
        postJson("/api/admin/orders/{$this->order->uuid}/retry")->assertOk();

        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->not->toBeNull()->evoliz_error->toBeNull()
            ->and(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::SettledStandalone)->exists())->toBeTrue();
        // Only the push of the approval, none for the retry.
        Queue::assertPushed(PushOrderJob::class, 1);

        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk()->assertJsonPath('exported', [$this->order->number]);
        expect($this->order->fresh()->exported_at)->not->toBeNull()
            ->and((float) $this->variety->fresh()->stock_qty)->toBe(8.0);
        Http::assertNothingSent();
    });

    it('offers nothing and refuses the retry while Evoliz may hold the BL', function () {
        failedOrder($this->order, $this->admin);
        deliveryCreate($this->order, 'failed', 502);
        licenceExpired();
        actingAs($this->admin);

        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_retry', false));
        postJson("/api/admin/orders/{$this->order->uuid}/retry")->assertStatus(409)->assertJsonFragment(['message' => __('Evoliz may already hold the BL of this order (its creation got no clear answer): it stays failed until Evoliz can be asked, with "Retry" once Evoliz is back.')]);

        expect($this->order->fresh()->status)->toBe(OrderStatus::Failed);
    });

    it('settles it for the export only on the admin\'s word that Evoliz holds no BL, and never sends it later', function () {
        failedOrder($this->order, $this->admin);
        deliveryCreate($this->order, 'failed', 502);
        licenceExpired();
        actingAs($this->admin);

        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_retry', false)->where('order.can_settle_checked', true));
        postJson("/api/admin/orders/{$this->order->uuid}/retry", ['checked_evoliz' => false])->assertStatus(409);
        postJson("/api/admin/orders/{$this->order->uuid}/retry", ['checked_evoliz' => true])->assertOk();

        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->not->toBeNull()
            ->and(ApprovalService::checkedInEvoliz($this->order))->toBeTrue()
            ->and(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::SettledStandalone)->where('user_id', $this->admin->id)->exists())->toBeTrue();
        // the timeline says who confirmed it, and when.
        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.activity', fn (Collection $activity) => $activity->contains(fn (array $entry) => $entry['action'] === 'settled_standalone'
                && $entry['user'] === $this->admin->name && $entry['created_at'] !== null
                && $entry['detail'] === 'Vérifié dans Evoliz : aucun bon de livraison pour cette commande')));
        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk()->assertJsonPath('exported', [$this->order->number]);
        postJson("/api/admin/orders/{$this->order->uuid}/unmark-exported")->assertOk();

        // Evoliz back: never offered nor sent, whatever the export says.
        TestLicence::install();
        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_send_to_evoliz', false));
        postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz")->assertStatus(409);
        Http::assertNothingSent();
    });

    it('never settles on the admin\'s word an order whose BL Evoliz is known to hold', function () {
        failedOrder($this->order, $this->admin);
        $this->order->fresh()->forceFill(['evoliz_delivery_id' => 3001])->save();
        licenceExpired();
        actingAs($this->admin);

        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_settle_checked', false));
        postJson("/api/admin/orders/{$this->order->uuid}/retry", ['checked_evoliz' => true])->assertStatus(409);
        expect($this->order->fresh()->status)->toBe(OrderStatus::Failed);
    });

    it('still pushes again on "Réessayer" with Evoliz running', function () {
        failedOrder($this->order, $this->admin);
        actingAs($this->admin);

        postJson("/api/admin/orders/{$this->order->uuid}/retry")->assertOk();

        // Queued again (the fake keeps the unique lock of the approval's push, so it counts one).
        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->toBeNull()
            ->and(ActivityLog::query()->where('order_id', $this->order->id)->where('action', ActivityAction::SettledStandalone)->exists())->toBeFalse();
        Queue::assertPushed(PushOrderJob::class);
    });
});

describe('S2: exported once', function () {
    it('never sends an order exported during a lapse and unmarked since, nor offers it', function () {
        licenceExpired();
        app(ApprovalService::class)->approve($this->admin, $this->order);
        actingAs($this->admin);
        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk();
        postJson("/api/admin/orders/{$this->order->uuid}/unmark-exported")->assertOk();

        TestLicence::install();
        expect(SyncMode::provider())->toBe('evoliz')
            ->and($this->order->fresh()->exported_at)->toBeNull();

        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_send_to_evoliz', false));
        postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz")->assertStatus(409);
        expect(fn () => app(ApprovalService::class)->sendToEvoliz($this->admin, $this->order->fresh()))->toThrow(ConflictHttpException::class);

        // A push that reaches it anyway does nothing.
        $this->order->fresh()->forceFill(['standalone_at' => null])->save();
        app(OrderPush::class)->push($this->order->id);
        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->evoliz_delivery_id->toBeNull();
        Http::assertNothingSent();
    });
});

describe('the export of an order approved during a lapse', function () {
    it('can be marked exported again once Evoliz is back, unmarked again, and is never sent', function () {
        licenceExpired();
        app(ApprovalService::class)->approve($this->admin, $this->order);
        actingAs($this->admin);
        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk();
        postJson("/api/admin/orders/{$this->order->uuid}/unmark-exported")->assertOk();

        TestLicence::install();
        expect(SyncMode::provider())->toBe('evoliz');
        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.can_mark_exported', true)->where('order.can_unmark_exported', false)->where('order.can_send_to_evoliz', false));

        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk()->assertJsonPath('exported', [$this->order->number]);
        // with Evoliz running the stock is Evoliz's (its sync overwrites ours): no local move.
        expect($this->order->fresh()->exported_at)->not->toBeNull()
            ->and((float) $this->variety->fresh()->stock_qty)->toBe(10.0);
        get("/orders/{$this->order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_unmark_exported', true)->where('order.can_mark_exported', false));

        postJson("/api/admin/orders/{$this->order->uuid}/unmark-exported")->assertOk();
        postJson("/api/admin/orders/{$this->order->uuid}/send-to-evoliz")->assertStatus(409);
        Http::assertNothingSent();
    });

    it('refuses to mark exported an order Evoliz may already hold', function () {
        app(ApprovalService::class)->approve($this->admin, $this->order);
        licenceExpired();
        $this->order->fresh()->forceFill(['standalone_at' => now()])->save();
        deliveryCreate($this->order, 'running', null);
        actingAs($this->admin);

        postJson('/api/admin/orders/mark-exported', ['uuids' => [$this->order->uuid]])->assertOk()
            ->assertJsonPath('exported', [])
            ->assertJsonPath('skipped.0.reason', __('Evoliz may already hold the BL of this order: it is not marked exported.'));
        expect($this->order->fresh()->exported_at)->toBeNull();
    });
});

describe('S6: one reading of the mode', function () {
    it('pushes an order whose approval began with Evoliz running, even if Evoliz stops during it', function () {
        ActivityLog::created(function (ActivityLog $entry) {
            if ($entry->action === ActivityAction::Approved) {
                licenceExpired();
            }
        });

        app(ApprovalService::class)->approve($this->admin, $this->order);

        expect($this->order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->toBeNull();
        Queue::assertPushed(PushOrderJob::class, fn (PushOrderJob $job) => $job->orderId === $this->order->id);
    });
});
