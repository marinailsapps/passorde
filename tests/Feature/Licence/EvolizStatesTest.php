<?php

use App\Domain\Orders\ApprovalService;
use App\Enums\ActivityAction;
use App\Enums\OrderStatus;
use App\Integrations\Sync\Jobs\PushArticleJob;
use App\Integrations\Sync\Jobs\PushClientJob;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Push\OrderPush;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\SyncMode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceState;
use Passorde\Testing\TestLicence;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

// without a usable licence the app runs standalone, fully; nothing is re-read later.

beforeEach(function () {
    travelTo('2026-11-13 09:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->seller = User::factory()->create();
    $this->variety = Article::factory()->create(['stock_qty' => 10, 'is_stocked' => true]);
    $this->client = Client::factory()->create();
});

/** The signed test licence with one field changed after signing. */
function tamperedLicence(): void
{
    $fields = LicenceKey::decode(TestLicence::signed(LicenceState::Grace));
    $fields->expires_at = '2099-12-31';
    app(LicenceStore::class)->replace(LicenceKey::encode($fields));
    EvolizState::flush();
}

function pendingStateOrder(): Order
{
    return Order::factory()->for(test()->client)->status(OrderStatus::Pending)->withLines([[test()->variety, 2]])->create();
}

dataset('standalone states', [
    'switch off' => [fn () => fileMode(), null],
    'switch on, no licence key' => [fn () => licenceMissing(), EvolizState::REASON_LICENCE],
    'lapsed (past the grace)' => [fn () => licenceExpired(), EvolizState::REASON_LICENCE],
    'wrong product' => [fn () => licenceWith(['product' => 'another-product']), EvolizState::REASON_LICENCE],
    'connector not in the licence' => [fn () => licenceWith(['connectors' => ['sage']]), EvolizState::REASON_LICENCE],
    'tampered key' => [fn () => tamperedLicence(), EvolizState::REASON_LICENCE],
    'switch on, a key missing' => [fn () => config()->set('passorde-connect.evoliz.secret_key', null), EvolizState::REASON_CREDENTIALS],
]);

it('runs Evoliz with a valid licence, in its grace, and with Evoliz among the connectors', function (?Closure $install) {
    $install?->__invoke();

    expect(EvolizState::active())->toBeTrue()
        ->and(SyncMode::provider())->toBe('evoliz')
        ->and(EvolizState::reason())->toBeNull();
})->with([
    'valid' => [null],
    'grace' => [fn () => licenceGrace()],
    'connectors list with evoliz' => [fn () => licenceWith(['connectors' => ['evoliz', 'sage']])],
]);

it('runs standalone in every other state, saying why to admins only', function (Closure $state, ?string $reason) {
    $state();

    expect(SyncMode::isFile())->toBeTrue()
        ->and(EvolizState::reason())->toBe($reason);

    actingAs($this->seller)->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('syncProvider', 'file')
        ->where('syncLicence', null)
        ->where('syncMismatch', false));

    $licence = null;
    actingAs($this->admin)->get('/')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$licence) {
        $page->where('syncProvider', 'file');
        $licence = $page->toArray()['props']['syncLicence'];
    });

    // The licence banner for a licence reason; nothing about the licence with the switch off.
    expect(match ($reason) {
        null => $licence === null,
        EvolizState::REASON_LICENCE => in_array($licence['state'] ?? null, ['expired', 'invalid'], true),
        default => ($licence['state'] ?? null) === 'valid',
    })->toBeTrue();
})->with('standalone states');

it('keeps export, import and catalogue edits available, and no sync to fail', function (Closure $state) {
    $state();
    $order = pendingStateOrder();
    actingAs($this->admin);

    app(ApprovalService::class)->approve($this->admin, $order);
    get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertOk();
    get('/admin/import')->assertOk();
    patch("/admin/catalog/{$this->variety->id}", ['designation' => 'Edited', 'unit_price_ht' => 12, 'vat_rate' => 5.5, 'stock_qty' => 8, 'is_stocked' => true, 'is_active' => true])->assertSessionHasNoErrors();
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    // The sync buttons are gone rather than failing.
    get('/admin/sync')->assertNotFound();

    expect($order->fresh())->status->toBe(OrderStatus::Approved)->exported_at->not->toBeNull()->evoliz_error->toBeNull()
        ->and($this->variety->fresh()->designation)->toBe('Edited');
    Http::assertNothingSent();
})->with('standalone states');

it('never pushes an order exported by hand during a lapse, after the renewal either', function () {
    Queue::fake();
    licenceExpired();
    $order = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $order);
    actingAs($this->admin);
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();

    TestLicence::install();
    expect(SyncMode::provider())->toBe('evoliz');

    // A push that reaches it anyway (replayed job, retry) does nothing.
    app(OrderPush::class)->push($order->id);
    app()->call([new PushOrderJob($order->id), 'handle']);

    expect($order->fresh())->status->toBe(OrderStatus::Approved)->evoliz_delivery_id->toBeNull()->exported_at->not->toBeNull()
        ->and(fn () => app(ApprovalService::class)->sendToEvoliz($this->admin, $order->fresh()))->toThrow(ConflictHttpException::class);
    postJson("/api/admin/orders/{$order->uuid}/send-to-evoliz")->assertStatus(409);
    get("/orders/{$order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('order.can_send_to_evoliz', false)->where('order.status_label', 'Exportée'));
    Http::assertNothingSent();
});

it('shows an order approved during a lapse as approved outside Evoliz once renewed, sent only on request', function () {
    Queue::fake();
    licenceExpired();
    $order = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $order);
    TestLicence::install();
    actingAs($this->admin);

    get("/orders/{$order->uuid}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('order.can_send_to_evoliz', true)
        ->where('order.status_label', 'Validée hors Evoliz'));
    Queue::assertNotPushed(PushOrderJob::class);

    postJson("/api/admin/orders/{$order->uuid}/send-to-evoliz")->assertOk()->assertJsonPath('order.can_send_to_evoliz', false);

    Queue::assertPushed(PushOrderJob::class, fn (PushOrderJob $job) => $job->orderId === $order->id);
    expect($order->fresh()->standalone_at)->toBeNull()
        ->and(ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::SentToEvoliz)->exists())->toBeTrue();
});

it('says "Envoi vers Evoliz en cours" only while a push is really pending (rehearsal F12)', function () {
    Queue::fake();
    actingAs($this->admin);
    $pending = fn (Order $order) => get("/orders/{$order->uuid}")->assertOk()->viewData('page')['props']['order']['push_pending'];

    // Approved with Evoliz running: the push is queued.
    $sent = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $sent);
    expect($pending($sent))->toBeTrue();

    // Approved during a lapse: nothing is sent until "Envoyer à Evoliz".
    licenceExpired();
    $lapse = pendingStateOrder();
    $exported = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $lapse);
    app(ApprovalService::class)->approve($this->admin, $exported);
    TestLicence::install();
    expect($pending($lapse))->toBeFalse();

    // Exported by hand, now or once and unmarked: never sent.
    app(ApprovalService::class)->markExported($this->admin, $exported->fresh());
    expect($pending($exported))->toBeFalse();
    app(ApprovalService::class)->unmarkExported($this->admin, $exported->fresh());
    expect($pending($exported))->toBeFalse();

    app(ApprovalService::class)->sendToEvoliz($this->admin, $lapse->fresh());
    expect($pending($lapse))->toBeTrue();

    // Never for a seller.
    actingAs($this->seller);
    $own = pendingStateOrder();
    $own->forceFill(['user_id' => $this->seller->id, 'status' => OrderStatus::Approved])->save();
    expect($pending($own))->toBeFalse();
});

it('settles a push queued before the lapse as standalone instead of failing', function () {
    Queue::fake();
    $order = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $order);
    Queue::assertPushed(PushOrderJob::class);

    licenceExpired();
    app()->call([new PushOrderJob($order->id), 'handle']);

    expect($order->fresh())->status->toBe(OrderStatus::Approved)->standalone_at->not->toBeNull()->evoliz_error->toBeNull()
        ->and(ActivityLog::query()->where('order_id', $order->id)->where('action', ActivityAction::SettledStandalone)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('fails, for a retry, an order whose BL was already created when Evoliz stopped', function () {
    Queue::fake();
    $order = pendingStateOrder();
    app(ApprovalService::class)->approve($this->admin, $order);
    $order->forceFill(['evoliz_delivery_id' => 123])->save();

    licenceExpired();
    app()->call([new PushOrderJob($order->id), 'handle']);

    expect($order->fresh())->status->toBe(OrderStatus::Failed)->standalone_at->toBeNull()
        ->evoliz_error->toContain('Réessayer');
    Http::assertNothingSent();
});

it('never stamps an order approved in Evoliz mode', function () {
    Queue::fake();
    $order = pendingStateOrder();

    app(ApprovalService::class)->approve($this->admin, $order);

    expect($order->fresh()->standalone_at)->toBeNull();
    Queue::assertPushed(PushOrderJob::class);
});

it('lets writes queued before the lapse end quietly: new variety made locally, client kept, nothing sent', function () {
    $request = ArticleRequest::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $this->seller->id, 'name' => 'NEWVARIETY', 'category' => 'POMMIER', 'variant' => 'nu',
        'unit_price_ht' => 20, 'reference' => 'NEWVARIETY POM', 'designation' => 'NEWVARIETY', 'status' => ArticleRequest::PENDING,
    ]);
    $typed = Client::factory()->fromApp()->create();
    licenceExpired();

    app()->call([new PushArticleJob($request->id), 'handle']);
    app()->call([new PushClientJob($typed->id), 'handle']);

    expect($request->fresh()->status)->toBe(ArticleRequest::CREATED)
        ->and(Article::query()->where('reference', 'NEWVARIETY POM')->whereNull('evoliz_article_id')->exists())->toBeTrue()
        ->and($typed->fresh()->evoliz_error)->toBeNull()
        ->and($typed->fresh()->evoliz_client_id)->toBeNull();
    Http::assertNothingSent();
});

it('gives back on unmarking exactly what marking took', function () {
    Queue::fake();
    actingAs($this->admin);
    $service = app(ApprovalService::class);

    // Approved during a lapse, marked exported once Evoliz is back: no local move.
    licenceExpired();
    $order = pendingStateOrder();
    $service->approve($this->admin, $order);
    TestLicence::install();
    EvolizState::flush();
    $service->markExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('10.00');

    // Unmarked during the next lapse: it took nothing, it gives nothing back.
    licenceExpired();
    $service->unmarkExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('10.00');

    // Marked during the lapse: 2 taken; the catalogue then changes the stock; unmarked: the 2 come back, no more.
    $service->markExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('8.00');
    $this->variety->update(['stock_qty' => 5]);
    $service->unmarkExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('7.00');
});

it('moves no stock when marking and unmarking with Evoliz running, and the same each time in a lapse', function () {
    Queue::fake();
    actingAs($this->admin);
    $service = app(ApprovalService::class);

    licenceExpired();
    $order = pendingStateOrder();
    $service->approve($this->admin, $order);
    TestLicence::install();
    EvolizState::flush();

    // Evoliz running: marked and unmarked, the stock is Evoliz's.
    $service->markExported($this->admin, $order->fresh());
    $service->unmarkExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('10.00');

    // In a lapse: mark, unmark, mark again takes the 2 once each time, gives them back once.
    licenceExpired();
    $service->markExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('8.00');
    $service->unmarkExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('10.00');
    $service->markExported($this->admin, $order->fresh());
    expect($this->variety->fresh()->stock_qty)->toBe('8.00');
});
