<?php

use App\Domain\Ops\HealthReport;
use App\Domain\Orders\ApprovalService;
use App\Domain\Orders\OrderService;
use App\Enums\EvolizStockStatus;
use App\Enums\OrderStatus;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\AdjustStockJob;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\Push\OrderPush;
use App\Integrations\Sync\SyncProvider;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\StockAdjustment;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\LicenceManager;
use Passorde\Testing\TestLicence;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo('2026-11-07 09:00:00'); // 10:00 in Paris
    evolizTestKeys();
    $this->admin = User::factory()->admin()->create();
    $this->seller = User::factory()->create();
});

/** A pending order of a client already in Evoliz, one line. */
function licencedOrder(): Order
{
    $client = Client::factory()->create(['evoliz_client_id' => 7000160, 'evoliz_code' => 'EXEMPLEAMB', 'postcode' => '19380', 'town' => 'BOISJOLI']);
    $article = Article::factory()->create(['evoliz_article_id' => 7000240, 'reference' => 'ASTRA MT POM', 'unit_price_ht' => 53, 'vat_rate' => 5.5]);
    $paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);

    return app(OrderService::class)->save(User::factory()->create(), (string) Str::uuid(), [
        'client_id' => $client->id,
        'lines' => [['article_id' => $article->id, 'quantity' => 1, 'unit_price_ht' => 53, 'is_free' => false, 'position' => 0]],
        'paytype_id' => $paytype->id,
    ])['order'];
}

/** @return array<string, mixed>|null */
function sharedLicence(User $user): ?array
{
    $licence = null;
    actingAs($user)->get('/')->assertInertia(function (AssertableInertia $page) use (&$licence) {
        $licence = $page->toArray()['props']['syncLicence'];
    });

    return $licence;
}

it('shares the licence with admins in each state', function (string $state, ?Closure $install, array $expected) {
    $install?->__invoke();

    expect(sharedLicence($this->admin))->toMatchArray($expected);
})->with([
    'valid' => ['valid', null, ['state' => 'valid', 'reason' => "La licence est valide et n'expire pas.", 'expires_at' => null, 'grace_until' => null]],
    'grace' => ['grace', fn () => licenceGrace(), ['state' => 'grace', 'expires_at' => '2026-11-04', 'grace_until' => '2026-11-19']],
    'expired' => ['expired', fn () => licenceExpired(), ['state' => 'expired', 'reason' => "La période payée s'est terminée le 8 octobre 2026 et la période de grâce est terminée. Renouvelez la licence sur https://apps.marinails.site/passorde, puis installez la clé reçue par e-mail.", 'expires_at' => '2026-10-08', 'grace_until' => null]],
    'invalid' => ['invalid', fn () => licenceInvalid(), ['state' => 'invalid', 'reason' => 'La signature de la licence est incorrecte.']],
    'missing' => ['missing', fn () => licenceMissing(), ['state' => 'invalid', 'reason' => "Aucune licence n'est installée.", 'expires_at' => null]],
]);

it('shows sellers nothing about the licence, and nothing in file mode', function () {
    licenceExpired();
    expect(sharedLicence($this->seller))->toBeNull();

    fileMode();
    expect(sharedLicence($this->admin))->toBeNull();
});

it('verifies the licence once per page', function () {
    licenceGrace();
    $store = new class(app(LicenceStore::class)) implements LicenceStore
    {
        public int $reads = 0;

        public function __construct(private readonly LicenceStore $inner) {}

        public function current(): ?string
        {
            $this->reads++;

            return $this->inner->current();
        }

        public function previous(): ?string
        {
            return $this->inner->previous();
        }

        public function replace(string $raw): void
        {
            $this->inner->replace($raw);
        }

        public function lastRefreshAt(): ?DateTimeImmutable
        {
            return $this->inner->lastRefreshAt();
        }

        public function markRefreshed(DateTimeImmutable $at): void
        {
            $this->inner->markRefreshed($at);
        }
    };
    app()->instance(LicenceStore::class, $store);
    app()->forgetInstance(LicenceManager::class);

    actingAs($this->admin)->get('/')->assertOk();

    expect($store->reads)->toBe(1);
});

it('adds a licence section to the digest in grace, expired and invalid only', function () {
    $section = fn () => collect(app(HealthReport::class)->sections())->firstWhere('title', 'Licence Passorde');

    expect($section())->toBeNull();

    licenceGrace();
    expect($section()['lines'][0])->toStartWith("La période payée s'est terminée le 4 novembre 2026 ; la période de grâce court jusqu'au 18 novembre 2026 inclus.")
        ->and($section()['lines'][1])->toBe('Tout fonctionne encore pendant la période de grâce.')
        ->and(substr_count(implode(' ', $section()['lines']), 'Renouvelez'))->toBe(1);

    licenceExpired();
    expect($section()['lines'])->toBe([
        "La période payée s'est terminée le 8 octobre 2026 et la période de grâce est terminée. Renouvelez la licence sur https://apps.marinails.site/passorde, puis installez la clé reçue par e-mail.",
        "Rien n'est envoyé à Evoliz et aucune synchronisation ne tourne ; l'application fonctionne seule (les commandes validées s'exportent) jusqu'à l'installation d'une licence valide.",
    ]);

    licenceMissing();
    expect($section()['lines'][0])->toBe("Aucune licence n'est installée.");

    fileMode();
    expect($section())->toBeNull();
});

it('still pushes an order during the grace', function () {
    licenceGrace();
    $order = licencedOrder();
    scriptEvoliz([
        'GET /articles/7000240' => evolizJson(evolizFixture('article-7000240')),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]),
        'POST /deliveries' => evolizJson([...evolizFixture('delivery-created'), 'external_document_number' => $order->number], 201),
    ]);

    app(ApprovalService::class)->approve($this->admin, $order);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent);
});

it('lets an expired licence approve standalone, never pushes it later, and sends it on the admin\'s word once renewed', function (Closure $licence) {
    $licence();
    $order = licencedOrder();
    scriptEvoliz([
        'GET /articles/7000240' => evolizJson(evolizFixture('article-7000240')),
        'GET /deliveries' => evolizJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]),
        'POST /deliveries' => evolizJson([...evolizFixture('delivery-created'), 'external_document_number' => $order->number], 201),
    ]);

    app(ApprovalService::class)->approve($this->admin, $order);

    // Standalone: approved, exportable, no error for anyone, nothing sent.
    expect($order->fresh())
        ->status->toBe(OrderStatus::Approved)
        ->approved_by->toBe($this->admin->id)
        ->evoliz_error->toBeNull()
        ->standalone_at->not->toBeNull();
    Http::assertNothingSent();

    // Renewed: nothing happens on its own (no silent re-reading of the order)...
    TestLicence::install();
    app(OrderPush::class)->push($order->id);
    expect($order->fresh()->status)->toBe(OrderStatus::Approved);
    Http::assertNothingSent();

    // ...until the admin sends it.
    app(ApprovalService::class)->sendToEvoliz($this->admin, $order->fresh());

    expect($order->fresh())->status->toBe(OrderStatus::Sent)->standalone_at->toBeNull();
})->with([
    'expired' => [fn () => licenceExpired()],
    'invalid' => [fn () => licenceInvalid()],
    'missing' => [fn () => licenceMissing()],
]);

it('fails a stock entry without calling Evoliz when the licence is expired', function () {
    licenceExpired();
    $article = Article::factory()->create(['evoliz_article_id' => 7000240, 'stock_qty' => 4]);
    $entry = StockAdjustment::query()->create([
        'kind' => 'broken', 'article_id' => $article->id, 'quantity' => 1, 'happened_on' => '2026-11-07', 'user_id' => $this->admin->id,
        'evoliz_status' => EvolizStockStatus::Pending,
    ]);

    AdjustStockJob::dispatchSync($entry->id);

    expect($entry->fresh()->only(['evoliz_status', 'evoliz_error']))->toBe([
        'evoliz_status' => EvolizStockStatus::Failed, 'evoliz_error' => "Licence Passorde expirée : rien n'est envoyé à Evoliz",
    ]);
    Http::assertNothingSent();
});

it('skips a sync queued before the licence lapsed, without calling Evoliz or failing', function () {
    $run = SyncRun::queue(SyncKind::Paytypes, SyncTrigger::Console);
    licenceExpired();

    SyncJob::perform(app(SyncProvider::class), $run);

    expect($run->fresh()->only(['status', 'error']))->toBe([
        'status' => SyncStatus::Skipped, 'error' => "Licence Passorde expirée : rien n'est envoyé à Evoliz",
    ]);
    Http::assertNothingSent();
});

it('serves the licence page to admins only, with no inline style or script', function () {
    licenceGrace();

    get('/passorde/licence')->assertRedirect('/login');
    actingAs($this->seller)->get('/passorde/licence')->assertForbidden();
    actingAs($this->seller)->post('/passorde/licence', ['licence' => '{}'])->assertForbidden();

    $html = actingAs($this->admin)->get('/passorde/licence')->assertOk()->assertSee('Période de grâce')->getContent();
    expect($html)->not->toContain('<style')->not->toContain('style=')->not->toContain('<script');
    actingAs($this->admin)->get('/passorde/licence.css')->assertOk();
});

it('keeps the phones working on an expired licence', function () {
    licenceExpired();
    $client = Client::factory()->create();
    $article = Article::factory()->create();
    $paytype = Paytype::create(['evoliz_paytype_id' => 2, 'label' => 'Virement', 'is_enabled' => true]);

    actingAs($this->seller)->putJson('/api/orders/'.Str::uuid(), orderPayload($client, [[$article, 1]], $paytype))->assertCreated();
    actingAs($this->seller)->getJson('/api/clients')->assertOk();

    expect(Order::query()->count())->toBe(1);
    Http::assertNothingSent();
});

/** Whether the nightly passorde:licence refresh is due to run now. */
function licenceRefreshScheduled(): bool
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'passorde:licence refresh'));

    expect($event)->not->toBeNull();

    return $event->filtersPass(app());
}

it('schedules the licence refresh for a licence with a token, lifetime included (Passorde\'s isRefreshable)', function (?Closure $install, bool $scheduled) {
    $install?->__invoke();

    expect(licenceRefreshScheduled())->toBe($scheduled);
})->with([
    'subscription' => [fn () => TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30']), true],
    'subscription in grace' => [fn () => licenceGrace(), true],
    'subscription expired' => [fn () => licenceExpired(), true],
    // Its updates_until is extended by the service.
    'lifetime with a token' => [null, true],
    'lifetime without a token' => [fn () => TestLicence::install(overrides: ['licence_token' => null]), false],
    'subscription without a token' => [fn () => TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30', 'licence_token' => null]), false],
    'missing' => [fn () => licenceMissing(), false],
    'invalid' => [fn () => licenceInvalid(), false],
]);

it('never schedules the licence refresh in file mode', function () {
    TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30']);
    expect(licenceRefreshScheduled())->toBeTrue();

    fileMode();

    expect(licenceRefreshScheduled())->toBeFalse();
});
