<?php

use App\Domain\Ops\HealthReport;
use App\Domain\Orders\ApprovalService;
use App\Enums\OrderStatus;
use App\Enums\SyncKind;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\PushOrderJob;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\Jobs\TestConnectionJob;
use App\Integrations\Sync\Licence\HostLicenceStore;
use App\Integrations\Sync\SyncProvider;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\SyncMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceState;
use Passorde\Testing\TestLicence;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

// The add-on as an administrator and a seller see it: the switch, the licence states, renewal, and no contact with Evoliz without a usable key.

beforeEach(function () {
    travelTo('2026-11-13 09:00:00');
    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function addOnKey(LicenceState $state = LicenceState::Valid, array $overrides = []): string
{
    return TestLicence::signed($state, $overrides);
}

/** @return array<string, mixed>|null */
function adminLicenceProp(User $admin): ?array
{
    $licence = null;
    actingAs($admin)->get('/')->assertInertia(function (AssertableInertia $page) use (&$licence) {
        $licence = $page->toArray()['props']['syncLicence'];
    });

    return $licence;
}

it('subscription ended: 14 days of grace with a warning, then synchronisation stops; the banner says why and how to renew', function () {
    TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2026-11-12']);
    EvolizState::flush();
    expect(SyncMode::provider())->toBe('evoliz');

    // Day after the end: grace, everything runs, a warning with how to renew.
    travelTo('2026-11-15 09:00:00');
    EvolizState::flush();
    $grace = adminLicenceProp($this->admin);
    expect(SyncMode::provider())->toBe('evoliz')
        ->and($grace['state'])->toBe('grace')
        // Passorde Connect's reason says how to renew; the app adds no second renew line.
        ->and($grace['reason'])->toContain('Renouvelez la licence sur')
        ->and($grace['renew'])->toBeNull();

    // Past the 14 days: synchronisation stops, the banner says why and how to renew.
    travelTo('2026-11-29 09:00:00');
    EvolizState::flush();
    $expired = adminLicenceProp($this->admin);
    expect(SyncMode::isFile())->toBeTrue()
        ->and($expired['state'])->toBe('expired')
        ->and($expired['reason'])->toContain('12 novembre 2026')
        ->and(substr_count($expired['reason'], 'Renouvelez'))->toBe(1)
        ->and($expired['renew'])->toBeNull();

    $run = SyncRun::queue(SyncKind::Articles, SyncTrigger::Console);
    SyncJob::perform(app(SyncProvider::class), $run);
    expect($run->fresh()->status->value)->toBe('skipped');

    // Orders can still be taken, approved and exported.
    Queue::fake();
    $order = Order::factory()->for(Client::factory()->create())->status(OrderStatus::Pending)->withLines([[Article::factory()->create(), 1]])->create();
    app(ApprovalService::class)->approve($this->admin, $order);
    actingAs($this->admin)->get('/admin/orders/export.xlsx?uuids[]='.$order->uuid)->assertOk();
    Http::assertNothingSent();
});

it('switch off: no mention of licences anywhere, admins included', function (Closure $off) {
    $off();

    actingAs($this->admin)->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('syncLicence', null)
        ->where('evolizState', null));
    get('/passorde/licence')->assertNotFound();
    get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page->where('evoliz', null));
    expect(collect(app(HealthReport::class)->sections())->pluck('title'))->not->toContain('Licence Passorde');
})->with([
    'switched off, no keys' => [fn () => fileMode()],
    // EVOLIZ_ENABLED unset while the keys and a valid licence are all there: still off.
    'switch unset, keys and licence present' => [fn () => config()->set('server.evoliz_enabled', null)],
]);

it('the switch unset with keys and a licence present: standalone, nothing reaches Evoliz', function () {
    config()->set('server.evoliz_enabled', null);
    config()->set('passorde-connect.writes_enabled', true);
    Http::fake();

    expect(EvolizState::credentialsComplete())->toBeTrue()
        ->and(EvolizState::enabled())->toBeFalse()
        ->and(SyncMode::isFile())->toBeTrue();

    artisan('sync:articles')->assertExitCode(1);
    $order = Order::factory()->for(Client::factory()->create())->status(OrderStatus::Pending)->withLines([[Article::factory()->create(), 1]])->create();
    app(ApprovalService::class)->approve($this->admin, $order);
    app()->call([new PushOrderJob($order->id), 'handle']);

    expect($order->fresh()->status)->toBe(OrderStatus::Approved);
    Http::assertNothingSent();
});

it('switch on with no key or a wrong key: no contact with Evoliz, the admin block says what is missing', function (Closure $state, string $missing) {
    $state();

    actingAs($this->admin)->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evoliz.active', false)
        ->where('evoliz.missing', fn (Collection $items) => $items->contains(fn (string $item) => str_contains($item, $missing))));
    Http::assertNothingSent();
})->with([
    'no licence key' => [fn () => licenceMissing(), "Aucune licence n'est installée"],
    'wrong key' => [fn () => licenceInvalid(), 'signature'],
    'no secret key' => [fn () => config()->set('passorde-connect.evoliz.secret_key', null), 'Clé secrète Evoliz : à saisir'],
]);

it('licence service unreachable: no effect, the check is made from the key itself', function () {
    TestLicence::install(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30']);
    config()->set('passorde-connect.licence.refresh_url', 'https://licensing.test');
    app()->forgetInstance(LicenceRefresher::class);
    Http::fake(['licensing.test/*' => fn () => throw new ConnectionException('unreachable')]);

    artisan('passorde:licence refresh')->assertExitCode(1);
    EvolizState::flush();

    expect(EvolizState::active())->toBeTrue()
        ->and(EvolizState::licence()?->state)->toBe(LicenceState::Valid);
});

it('renewal: the daily fetch replaces the key stored in the database', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => addOnKey(overrides: ['plan' => '12m', 'expires_at' => '2026-11-30'])])->assertSessionHasNoErrors();
    config()->set('passorde-connect.licence.refresh_url', 'https://licensing.test');
    app()->forgetInstance(LicenceRefresher::class);
    Http::fake(['licensing.test/licence/lic_test' => Http::response(TestLicence::signed(overrides: ['plan' => '12m', 'expires_at' => '2027-11-30']))]);

    artisan('passorde:licence refresh')->assertExitCode(0);
    EvolizState::flush();

    expect(Setting::read(HostLicenceStore::CURRENT))->toStartWith('pck1.')
        ->and(EvolizState::licence()?->licence?->expiresAt)->toBe('2027-11-30')
        ->and(app(HostLicenceStore::class)->lastRefreshAt())->not->toBeNull();
});

it('renewal: a key pasted by hand replaces the stored one', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => addOnKey(overrides: ['plan' => '12m', 'expires_at' => '2026-11-30'])]);
    $first = Setting::read(HostLicenceStore::CURRENT);

    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => addOnKey(overrides: ['plan' => '12m', 'expires_at' => '2027-11-30'])])->assertSessionHasNoErrors();
    EvolizState::flush();

    expect(Setting::read(HostLicenceStore::CURRENT))->not->toBe($first)
        ->and(Setting::read(HostLicenceStore::PREVIOUS))->toBe($first)
        ->and(EvolizState::licence()?->licence?->expiresAt)->toBe('2027-11-30');
});

it('once a valid key and working keys are saved, synchronisation starts', function () {
    licenceMissing();
    Queue::fake();

    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => addOnKey()])->assertSessionHas('success', 'Réglages Evoliz enregistrés. La première synchronisation a démarré.');

    Queue::assertPushed(SyncJob::class, 3);
    expect(SyncRun::query()->pluck('kind')->map->value->sort()->values()->all())->toBe(['articles', 'clients', 'paytypes']);

    // Saved again while it runs: nothing more is queued.
    actingAs($this->admin)->put('/admin/settings/evoliz', ['public_key' => 'other']);
    Queue::assertPushed(SyncJob::class, 3);
});

it('switch on and no key, the app works and Evoliz is never contacted, whatever the configuration says', function () {
    licenceMissing();
    // Every other setting says "go".
    config()->set('server.sync.schedule_enabled', true);
    config()->set('passorde-connect.writes_enabled', true);
    Http::fake();

    expect(SyncMode::isFile())->toBeTrue();

    artisan('sync:articles')->assertExitCode(1);
    $run = SyncRun::queue(SyncKind::Clients, SyncTrigger::Console);
    SyncJob::perform(app(SyncProvider::class), $run);
    app()->call([new TestConnectionJob, 'handle']);

    $order = Order::factory()->for(Client::factory()->create())->status(OrderStatus::Pending)->withLines([[Article::factory()->create(), 1]])->create();
    app(ApprovalService::class)->approve($this->admin, $order);
    app()->call([new PushOrderJob($order->id), 'handle']);

    expect($order->fresh()->status)->toBe(OrderStatus::Approved)
        ->and($order->fresh()->standalone_at)->not->toBeNull();
    Http::assertNothingSent();
});
