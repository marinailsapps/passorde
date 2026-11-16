<?php

use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\Sinks\ClientSink;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\SyncRun;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

const CLIENT_PAGES = [
    'clients' => ['clients-page-1', 'clients-page-2'],
    'contacts-clients' => ['contacts-clients'],
];

function runClientSync(): SyncRun
{
    $run = SyncRun::queue(SyncKind::Clients, SyncTrigger::Console);
    SyncJob::dispatchSync($run);

    return $run->fresh();
}

it('still syncs in test mode: reads are not writes', function () {
    fakeEvoliz(CLIENT_PAGES);
    config()->set('passorde-connect.writes_enabled', false);

    expect(runClientSync()->status)->toBe(SyncStatus::Succeeded)
        ->and(Client::query()->count())->toBe(5);
});

it('imports every page of clients and records the run', function () {
    fakeEvoliz(CLIENT_PAGES);

    $run = runClientSync();

    expect(Client::query()->count())->toBe(5)
        ->and($run->status)->toBe(SyncStatus::Succeeded)
        ->and($run->rows_seen)->toBe(5)
        ->and($run->rows_changed)->toBe(5)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/clients?') && $request['per_page'] === 100 && $request['page'] === 1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/clients?') && $request['per_page'] === 100 && $request['page'] === 2);
});

it('maps the recorded Evoliz payload', function () {
    fakeEvoliz(CLIENT_PAGES);

    runClientSync();

    $exemple = Client::query()->where('evoliz_client_id', 7000160)->sole();
    expect($exemple->only(['evoliz_code', 'company_name', 'contact_name', 'address_line', 'postcode', 'town', 'phone', 'email', 'is_active']))->toBe([
        'evoliz_code' => 'EXEMPLEAMB',
        'company_name' => 'EXEMPLE AMBROISE',
        // The favourite contact wins over the first one.
        'contact_name' => 'Ambroise Exemple',
        'address_line' => '1 RUE EXEMPLE',
        'postcode' => '32380',
        'town' => 'BOISJOLI',
        'phone' => '06.00.00.00.01',
        'email' => 'am.exemple@example.test',
        'is_active' => true,
    ])->and($exemple->source)->toBe(ClientSource::Evoliz)
        ->and($exemple->synced_at)->not->toBeNull();

    // Leading space trimmed; disabled contact ignored; empty phone stays null.
    $essai = Client::query()->where('evoliz_client_id', 7000270)->sole();
    expect($essai->company_name)->toBe('ESSAI CLAIRVAL')
        ->and($essai->contact_name)->toBeNull()
        ->and($essai->email)->toBeNull()
        ->and($essai->phone)->toBeNull();

    // Contact without email: name kept, email null.
    $proto = Client::query()->where('evoliz_client_id', 7000150)->sole();
    expect($proto->contact_name)->toBe('Hélène Proto')->and($proto->email)->toBeNull();
});

it('falls back to the mobile number and joins address lines', function () {
    $mapped = ClientSink::map([
        'code' => 'X',
        'name' => 'SARL  TEST',
        'phone' => '',
        'mobile' => '06 11 22 33 44',
        'address' => ['addr' => 'LIEU-DIT', 'addr2' => 'ROUTE DU MOULIN', 'addr3' => null, 'postcode' => '09200', 'town' => 'LAMBERTOU'],
        'enabled' => false,
    ], null);

    expect($mapped)->toMatchArray([
        'company_name' => 'SARL TEST',
        'phone' => '06 11 22 33 44',
        'address_line' => 'LIEU-DIT, ROUTE DU MOULIN',
        'is_active' => false,
    ]);
});

it('never overwrites the N° adhérent on a re-sync', function () {
    fakeEvoliz(CLIENT_PAGES);
    runClientSync();

    Client::query()->where('evoliz_client_id', 7000160)->update(['extra_field' => 'NP-58321', 'town' => 'ANCIENNE VILLE']);

    $run = runClientSync();

    $exemple = Client::query()->where('evoliz_client_id', 7000160)->sole();
    expect($exemple->extra_field)->toBe('NP-58321')
        ->and($exemple->town)->toBe('BOISJOLI')
        ->and($run->rows_changed)->toBe(1);
});

it('records in the client history what changed in Evoliz, not the first import', function () {
    fakeEvoliz(CLIENT_PAGES);
    runClientSync();
    expect(ClientChange::query()->count())->toBe(0);

    Client::query()->where('evoliz_client_id', 7000160)->update(['extra_field' => 'NP-58321', 'town' => 'ANCIENNE VILLE']);
    runClientSync();
    runClientSync();

    $change = ClientChange::query()->sole();
    expect($change->client->evoliz_client_id)->toBe(7000160)
        ->and($change->source)->toBe(ClientChangeSource::Evoliz)
        ->and($change->status)->toBe(ClientChangeStatus::Applied)
        ->and($change->user_id)->toBeNull()
        ->and($change->diff)->toBe(['town' => ['from' => 'ANCIENNE VILLE', 'to' => 'BOISJOLI']]);
});

it('moves updated_at only for clients that changed', function () {
    fakeEvoliz(CLIENT_PAGES);
    Pest\Laravel\travelTo('2026-10-01 08:00:00');
    runClientSync();
    Client::query()->where('evoliz_client_id', 7000160)->update(['town' => 'ANCIENNE VILLE']);

    Pest\Laravel\travelTo('2026-10-01 09:00:00');
    runClientSync();

    expect(Client::query()->where('updated_at', '>', '2026-10-01 08:30:00')->pluck('evoliz_client_id')->all())->toBe([7000160])
        ->and(Client::query()->where('synced_at', '<', '2026-10-01 09:00:00')->count())->toBe(0);
});

it('keeps clients that disappeared from Evoliz but flags them inactive', function () {
    $gone = Client::factory()->create(['evoliz_client_id' => 1234, 'extra_field' => 'R-7Q2K']);
    $appOnly = Client::factory()->fromApp()->create();
    fakeEvoliz(CLIENT_PAGES);

    runClientSync();

    expect($gone->fresh()->is_active)->toBeFalse()
        ->and($gone->fresh()->extra_field)->toBe('R-7Q2K')
        ->and($appOnly->fresh()->is_active)->toBeTrue();
});

it('records a failed run with the error', function () {
    fakeEvoliz(['clients' => [], 'contacts-clients' => ['contacts-clients']]);
    $existing = Client::factory()->create();

    $run = runClientSync();

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('404')
        ->and($existing->fresh()->is_active)->toBeTrue();
});

it('skips when another client sync holds the lock', function () {
    Http::fake();
    $lock = Cache::lock('passorde:sync:clients', 60);
    $lock->get();

    $run = runClientSync();

    expect($run->status)->toBe(SyncStatus::Skipped);
    Http::assertNothingSent();
    $lock->release();
});

it('runs from the console command', function () {
    fakeEvoliz(CLIENT_PAGES);

    artisan('evoliz:sync-clients')->assertSuccessful()->expectsOutputToContain('5 seen, 5 changed');

    expect(SyncRun::query()->sole())
        ->trigger->toBe(SyncTrigger::Console)
        ->status->toBe(SyncStatus::Succeeded);
});

it('decodes HTML entities in client names and addresses', function () {
    $mapped = ClientSink::map([
        'code' => 'DUPONTFR',
        'name' => 'DUPONT &amp; FR&Egrave;RES',
        'address' => ['addr' => 'Lieu-dit l&#039;Exemple', 'postcode' => '61100', 'town' => 'BOIS-&Eacute;TOIL&Eacute;'],
    ], ['name' => null, 'email' => null]);

    expect($mapped)->toMatchArray([
        'company_name' => 'DUPONT & FRÈRES',
        'address_line' => "Lieu-dit l'Exemple",
        'town' => 'BOIS-ÉTOILÉ',
    ]);
});
