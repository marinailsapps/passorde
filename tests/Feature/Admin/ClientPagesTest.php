<?php

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Models\Client;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    Client::factory()->create([
        'company_name' => 'EXEMPLE AMBROISE', 'contact_name' => 'Ambroise Exemple',
        'postcode' => '65380', 'town' => 'BOISJOLI', 'phone' => '06.00.00.00.01', 'extra_field' => 'NP-58321',
    ]);
    Client::factory()->create([
        'company_name' => 'SARL FICTIF', 'contact_name' => null,
        'postcode' => '87200', 'town' => 'LAMBERTOU', 'phone' => '09 70 00 00 00', 'extra_field' => null,
    ]);
    Client::factory()->fromApp()->create(['company_name' => 'EURL NOUVEAU', 'town' => 'GRENADIER', 'postcode' => '45140', 'phone' => null]);
    Client::factory()->create(['company_name' => 'ANCIEN CLIENT', 'town' => 'LAMBERTOU', 'is_active' => false]);
});

/**
 * @param  array<string, mixed>  $extra
 * @return list<string>
 */
function searchClients(string $query, array $extra = []): array
{
    $names = [];

    actingAs(test()->admin)->get('/admin/clients?'.http_build_query(['q' => $query, ...$extra]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$names) {
            $names = array_column($page->toArray()['props']['clients']['data'], 'company_name');
        });

    return $names;
}

it('searches clients by', function (string $query, array $expected) {
    expect(searchClients($query))->toBe($expected);
})->with([
    'company prefix' => ['exe', ['EXEMPLE AMBROISE']],
    'company substring' => ['FICT', ['SARL FICTIF']],
    'contact name' => ['ambroise', ['EXEMPLE AMBROISE']],
    'town' => ['lambertou', ['SARL FICTIF']],
    'postcode' => ['65380', ['EXEMPLE AMBROISE']],
    'N° adhérent' => ['5832', ['EXEMPLE AMBROISE']],
    'phone digits' => ['0600000', ['EXEMPLE AMBROISE']],
    'formatted phone' => ['06 00 00', ['EXEMPLE AMBROISE']],
    'two words' => ['sarl lambertou', ['SARL FICTIF']],
    'one character is ignored' => ['a', ['EURL NOUVEAU', 'EXEMPLE AMBROISE', 'SARL FICTIF']],
    'special characters' => ["o'brien & (test)", []],
]);

it('shows inactive clients only on request', function () {
    expect(searchClients('lambertou', ['inactive' => 1]))->toBe(['ANCIEN CLIENT', 'SARL FICTIF']);
});

it('filters clients not yet in Evoliz', function () {
    expect(searchClients('', ['unlinked' => 1]))->toBe(['EURL NOUVEAU']);
});

it('shows a client and edits the N° adhérent', function () {
    $client = Client::query()->where('company_name', 'SARL FICTIF')->sole();

    actingAs($this->admin)->get("/admin/clients/{$client->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Clients/Show')->where('client.linked', true));

    actingAs($this->admin)->patch("/admin/clients/{$client->id}", ['extra_field' => 'B2-77'])->assertSessionHasNoErrors();

    expect($client->fresh()->extra_field)->toBe('B2-77');
});

it('queues a client sync without calling Evoliz', function () {
    Queue::fake();
    Http::fake();

    actingAs($this->admin)->post('/admin/clients/sync')->assertRedirect()->assertSessionHas('success');

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncStatus::Queued)
        ->and($run->trigger)->toBe(SyncTrigger::Manual)
        ->and($run->user_id)->toBe($this->admin->id);
    Queue::assertPushed(SyncJob::class, fn (SyncJob $job) => $job->run->is($run));
    Http::assertNothingSent();

    actingAs($this->admin)->get('/admin/clients')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sync.active', true));
});
