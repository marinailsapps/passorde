<?php

use App\Integrations\Sync\Jobs\TestConnectionJob;
use App\Integrations\Sync\Licence\HostLicenceStore;
use App\Models\Setting;
use App\Models\User;
use App\Support\EvolizState;
use App\Support\SyncMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceState;
use Passorde\Licence\LicenceStatus;
use Passorde\Testing\TestLicence;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

// the "Evoliz" block of the settings page.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/**
 * A licence key as the shop sends it, for the test signing key.
 *
 * @param  array<string, mixed>  $overrides
 */
function licenceKey(LicenceState $state = LicenceState::Valid, array $overrides = []): string
{
    return TestLicence::signed($state, $overrides);
}

it('is for admins only, and has no Evoliz block with Evoliz switched off', function () {
    get('/admin/settings')->assertRedirect('/login');
    actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();

    actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Settings/Index')
        ->where('evoliz.active', true)
        ->where('evoliz.fields.company_id.source', 'env'));

    fileMode();
    actingAs($this->admin)->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page->where('evoliz', null));
    put('/admin/settings/evoliz', ['public_key' => 'x'])->assertNotFound();
    post('/admin/settings/evoliz/test')->assertNotFound();
});

it('saves the keys encrypted, never sends them back, and Passorde uses them', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', [
        'company_id' => ' 12345 ', 'public_key' => 'pub-typed-0042', 'secret_key' => 'sec-typed-very-secret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // In the database: ciphertext only.
    $raw = DB::table('settings')->pluck('value')->implode(' ');
    expect($raw)->not->toContain('pub-typed-0042')->not->toContain('sec-typed-very-secret')->not->toContain('12345')
        ->and(Setting::read('evoliz.secret_key'))->toBe('sec-typed-very-secret');

    // Passorde reads config at each call.
    expect(config('passorde-connect.evoliz.company_id'))->toBe(12345)
        ->and(config('passorde-connect.evoliz.public_key'))->toBe('pub-typed-0042')
        ->and(config('passorde-connect.evoliz.secret_key'))->toBe('sec-typed-very-secret');

    $html = actingAs($this->admin)->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evoliz.fields.company_id', ['set' => true, 'source' => 'database', 'hint' => '12345'])
        ->where('evoliz.fields.public_key', ['set' => true, 'source' => 'database', 'hint' => '…0042'])
        ->where('evoliz.fields.secret_key', ['set' => true, 'source' => 'database', 'hint' => null]))->getContent();
    expect($html)->not->toContain('sec-typed-very-secret')->not->toContain('pub-typed-0042');
});

it('keeps a saved key when its field is left empty, and goes back to .env when it is removed', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['secret_key' => 'typed-secret']);
    actingAs($this->admin)->put('/admin/settings/evoliz', ['secret_key' => '', 'public_key' => null]);
    expect(config('passorde-connect.evoliz.secret_key'))->toBe('typed-secret');

    actingAs($this->admin)->put('/admin/settings/evoliz', ['clear' => ['secret_key']])->assertSessionHasNoErrors();

    expect(Setting::read('evoliz.secret_key'))->toBeNull()
        ->and(config('passorde-connect.evoliz.secret_key'))->toBe('secret');
});

it('applies saved keys before each queued job, for a long-running worker', function () {
    Setting::write('evoliz.public_key', 'saved-by-another-process');
    Setting::flush();
    config()->set('passorde-connect.evoliz.public_key', 'stale');

    dispatch(fn () => null);

    expect(config('passorde-connect.evoliz.public_key'))->toBe('saved-by-another-process');
});

it('never puts a typed secret back into the session', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['company_id' => 'not-a-number', 'secret_key' => 'typed-secret', 'licence_key' => 'pck1.x'])
        ->assertSessionHasErrors('company_id');

    expect(session()->getOldInput())->not->toHaveKey('secret_key')->not->toHaveKey('licence_key')
        ->and(Setting::read('evoliz.secret_key'))->toBeNull();
});

it('installs a licence key in the database, after which it is the licence', function () {
    licenceMissing();
    expect(SyncMode::isFile())->toBeTrue();

    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => '  '.chunk_split(licenceKey(), 60, "\n")])->assertSessionHasNoErrors();

    $store = app(LicenceStore::class);
    expect($store)->toBeInstanceOf(HostLicenceStore::class)
        ->and($store->inDatabase())->toBeTrue()
        ->and(Setting::read(HostLicenceStore::CURRENT))->toStartWith('pck1.')
        ->and(DB::table('settings')->where('key', HostLicenceStore::CURRENT)->value('value'))->not->toContain('pck1.')
        ->and(EvolizState::active())->toBeTrue()
        ->and(SyncMode::provider())->toBe('evoliz');

    actingAs($this->admin)->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evoliz.licence.source', 'database')
        ->where('evoliz.licence.licence_id', 'lic_test')
        ->where('evoliz.licence.state', 'valid'));
});

it('accepts an expired key (standalone, dates shown) and refuses one that does not verify, keeping the current one', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => licenceKey(LicenceState::Expired)])->assertSessionHasNoErrors();
    expect(SyncMode::isFile())->toBeTrue()->and(currentLicence()->state)->toBe(LicenceState::Expired);

    $cases = [
        'tampered' => (function () {
            $fields = LicenceKey::decode(TestLicence::signed());
            $fields->company = 'Someone else';

            return LicenceKey::encode($fields);
        })(),
        'wrong product' => licenceKey(overrides: ['product' => 'another']),
        'connector not covered' => licenceKey(overrides: ['connectors' => ['sage']]),
        'old JSON file' => (string) json_encode(LicenceKey::decode(TestLicence::signed())),
        'not a key' => 'hello',
        'newer format' => 'pck2.abcd',
    ];

    foreach ($cases as $name => $typed) {
        actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => $typed, 'public_key' => 'not-saved'])
            ->assertSessionHasErrors('licence_key');
        expect(currentLicence()->state)->toBe(LicenceState::Expired, $name)
            ->and(Setting::read('evoliz.public_key'))->toBeNull();
    }
});

it('keeps a licence file installed before the settings page, refreshes included', function () {
    $store = app(LicenceStore::class);
    $before = $store->current();

    $store->replace(TestLicence::signed(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30']));

    expect($store->inDatabase())->toBeFalse()
        ->and(Setting::query()->count())->toBe(0)
        ->and($store->current())->not->toBe($before)
        ->and(is_file((string) config('passorde-connect.licence.path')))->toBeTrue();
});

it('stores a refreshed licence as a key once the key lives in the database', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => licenceKey()]);
    $store = app(LicenceStore::class);

    $store->replace(TestLicence::signed(overrides: ['plan' => '12m', 'expires_at' => '2027-06-30']));

    expect(Setting::read(HostLicenceStore::CURRENT))->toStartWith('pck1.')
        ->and(Setting::read(HostLicenceStore::PREVIOUS))->toStartWith('pck1.')
        ->and(currentLicence()->licence?->expiresAt)->toBe('2027-06-30');
});

it('tests the connection from the queue, never from the request', function () {
    Queue::fake();

    actingAs($this->admin)->post('/admin/settings/evoliz/test')->assertRedirect();

    Queue::assertPushed(TestConnectionJob::class);
    expect(TestConnectionJob::last()['status'])->toBe('pending');
    Http::assertNothingSent();
});

it('reports what Evoliz answers to the test', function () {
    Http::fake([
        'evoliz.test/api/login' => Http::response(['access_token' => 't', 'expires_at' => now()->addMinutes(20)->toIso8601String()]),
        'evoliz.test/api/v1/companies/10001/vat-rates*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]]),
    ]);

    app()->call([new TestConnectionJob, 'handle']);

    expect(TestConnectionJob::last())->toMatchArray(['status' => 'ok', 'message' => 'Evoliz répond avec ces clés.']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/vat-rates'));
});

it('reports wrong keys, and calls nothing while Evoliz cannot run', function () {
    Http::fake(['evoliz.test/api/login' => Http::response(['error' => 'Unauthorized'], 401)]);
    app()->call([new TestConnectionJob, 'handle']);
    expect(TestConnectionJob::last()['status'])->toBe('failed')
        ->and(TestConnectionJob::last()['message'])->toContain('clés');

    Http::fake();
    licenceExpired();
    app()->call([new TestConnectionJob, 'handle']);
    expect(TestConnectionJob::last()['message'])->toBe("Licence Passorde expirée : rien n'est envoyé à Evoliz");

    config()->set('passorde-connect.evoliz.secret_key', null);
    TestLicence::install();
    app()->call([new TestConnectionJob, 'handle']);
    expect(TestConnectionJob::last()['message'])->toBe("Clés Evoliz incomplètes : rien n'est envoyé à Evoliz");
    Http::assertNothingSent();
});

it('tells admins, not sellers, whether Evoliz runs', function () {
    config()->set('passorde-connect.evoliz.public_key', null);

    actingAs(User::factory()->create())->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('evolizState', null));
    actingAs($this->admin)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('evolizState', ['active' => false, 'reason' => 'credentials']));

    fileMode();
    actingAs($this->admin)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('evolizState', null));
});

function currentLicence(): LicenceStatus
{
    EvolizState::flush();

    return EvolizState::licence() ?? throw new RuntimeException('Evoliz is off');
}

it('names the plan in the words of the licence e-mails (rehearsal F3)', function (string $plan, string $label) {
    licenceWith(['plan' => $plan]);

    actingAs($this->admin)->get('/admin/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('evoliz.licence.plan', $plan)
        ->where('evoliz.licence.plan_label', $label));
})->with([
    '3 months' => ['3m', 'abonnement 3 mois'],
    '12 months' => ['12m', 'abonnement 12 mois'],
    'lifetime' => ['lifetime', 'licence à vie'],
    'a code this build does not know' => ['24m', '24m'],
]);

it('says that nothing changed when the form is saved as it is (rehearsal F4)', function () {
    actingAs($this->admin)->put('/admin/settings/evoliz', ['company_id' => '12345', 'public_key' => 'pub-typed-0042'])
        ->assertSessionHas('success', 'Réglages Evoliz enregistrés.');

    // Every field empty, then the same values again: nothing saved, and the page says so.
    foreach ([[], ['company_id' => '12345', 'public_key' => 'pub-typed-0042'], ['licence_key' => '', 'clear' => []]] as $form) {
        actingAs($this->admin)->put('/admin/settings/evoliz', $form)
            ->assertSessionHas('success', "Rien n'a changé dans les réglages Evoliz : les champs laissés vides gardent la valeur enregistrée.");
    }

    actingAs($this->admin)->put('/admin/settings/evoliz', ['clear' => ['public_key']])->assertSessionHas('success', 'Réglages Evoliz enregistrés.');
    actingAs($this->admin)->put('/admin/settings/evoliz', ['licence_key' => licenceKey()])->assertSessionHas('success', 'Réglages Evoliz enregistrés.');
});
