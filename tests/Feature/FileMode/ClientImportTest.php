<?php

use App\Domain\Clients\ClientImport;
use App\Domain\Import\CsvRejected;
use App\Enums\ActivityAction;
use App\Enums\ClientChangeSource;
use App\Enums\ClientChangeStatus;
use App\Enums\ClientSource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientChange;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    fileMode();
    $this->admin = User::factory()->admin()->create();
    $this->import = fn (string $csv, bool $dryRun = false) => app(ClientImport::class)->run($csv, $this->admin, $dryRun)->toArray();
    $this->fixture = (string) file_get_contents(base_path('tests/Fixtures/import/clients.csv'));
});

it('creates clients from the template format', function () {
    $report = ($this->import)($this->fixture);

    expect($report)->toMatchArray(['created' => 2, 'updated' => 0, 'errors' => 0]);

    $client = Client::query()->where('evoliz_code', 'C00001')->firstOrFail();
    expect($client->company_name)->toBe('SARL DE L EXEMPLE')
        ->and($client->contact_name)->toBe('Solène Exemple')
        ->and($client->address_line)->toBe('1 rue de l Exemple')
        ->and($client->postcode)->toBe('22100')
        ->and($client->town)->toBe('EXEMPLEVILLE')
        ->and($client->phone)->toBe('09 72 00 00 00')
        ->and($client->mobile)->toBe('06 00 00 00 00')
        ->and($client->email)->toBe('contact@example.test')
        ->and($client->extra_field)->toBe('A-015838')
        ->and($client->source)->toBe(ClientSource::Import)
        ->and($client->is_active)->toBeTrue()
        ->and($client->uuid)->toBeNull()
        ->and($client->evoliz_client_id)->toBeNull()
        ->and($client->isSettled())->toBeTrue();
    expect(ClientChange::query()->count())->toBe(0);
});

it('changes nothing when the same file comes back, not even updated_at', function () {
    ($this->import)($this->fixture);
    $before = Client::query()->orderBy('id')->pluck('updated_at', 'id')->map->toIso8601String();

    Carbon::setTestNow(now()->addHour());
    $report = ($this->import)($this->fixture);
    Carbon::setTestNow();

    expect($report)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'lines' => []])
        ->and(Client::query()->orderBy('id')->pluck('updated_at', 'id')->map->toIso8601String())->toEqual($before)
        ->and(ClientChange::query()->count())->toBe(0);
});

it('finds the client by code in any case and keeps what an empty cell does not state', function () {
    $client = Client::factory()->imported()->create(['evoliz_code' => 'C00007', 'company_name' => 'KEPT', 'contact_name' => 'Old Name', 'extra_field' => 'FR1']);

    $report = ($this->import)("code;societe;contact;numero_adherent\nc00007;;New Name;\n");

    expect($report)->toMatchArray(['created' => 0, 'updated' => 1])
        ->and($report['lines'][0]['fields'])->toBe(['contact']);
    $client->refresh();
    expect($client->company_name)->toBe('KEPT')
        ->and($client->contact_name)->toBe('New Name')
        ->and($client->extra_field)->toBe('FR1')
        ->and($client->evoliz_code)->toBe('C00007');
});

it('records each change in the client history, with import as the source', function () {
    $client = Client::factory()->imported()->create(['evoliz_code' => 'C00007', 'company_name' => 'OLD SA', 'contact_name' => null, 'is_active' => true]);

    ($this->import)("code;societe;contact;actif\nC00007;NEW SA;Someone;non\n");

    $change = ClientChange::query()->where('client_id', $client->id)->sole();
    expect($change->source)->toBe(ClientChangeSource::Import)
        ->and($change->status)->toBe(ClientChangeStatus::Applied)
        ->and($change->user_id)->toBe($this->admin->id)
        ->and($change->applied_at)->not->toBeNull()
        ->and($change->diff)->toBe([
            'company_name' => ['from' => 'OLD SA', 'to' => 'NEW SA'],
            'contact_name' => ['from' => null, 'to' => 'Someone'],
            'is_active' => ['from' => '1', 'to' => '0'],
        ]);
});

it('leaves absent clients alone, and an Evoliz client with the same code', function () {
    $absent = Client::factory()->imported()->create(['evoliz_code' => 'ABSENT', 'is_active' => true]);
    $synced = Client::factory()->create(['evoliz_code' => 'C00001', 'company_name' => 'FROM EVOLIZ']);

    ($this->import)($this->fixture);

    expect($absent->refresh()->is_active)->toBeTrue()
        ->and($synced->refresh()->company_name)->toBe('FROM EVOLIZ');
});

it('adopts the one typed client with the same company and postcode, keeping its uuid', function () {
    $typed = Client::factory()->fromApp()->create(['evoliz_code' => null, 'company_name' => 'Sarl  de l  Éxemple', 'postcode' => '22100', 'contact_name' => null]);
    $uuid = $typed->uuid;

    $report = ($this->import)($this->fixture);

    expect($report)->toMatchArray(['created' => 1, 'updated' => 1])
        ->and($report['lines'][0]['action'])->toBe('updated')
        ->and($report['lines'][0]['messages'][0])->toContain('Sarl');
    $typed->refresh();
    expect($typed->evoliz_code)->toBe('C00001')
        ->and($typed->uuid)->toBe($uuid)
        ->and($typed->source)->toBe(ClientSource::Import)
        ->and($typed->company_name)->toBe('SARL DE L EXEMPLE')
        ->and($typed->isSettled())->toBeTrue();
    expect(ClientChange::query()->where('client_id', $typed->id)->sole()->diff)->toHaveKey('evoliz_code');
    expect(Client::query()->count())->toBe(2);
});

it('finds the adopted client by code on the next import', function () {
    Client::factory()->fromApp()->create(['evoliz_code' => null, 'company_name' => 'SARL DE L EXEMPLE', 'postcode' => '22100']);

    ($this->import)($this->fixture);
    $report = ($this->import)($this->fixture);

    expect($report)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 2]);
});

it('creates a new client with a warning when several typed clients match', function () {
    Client::factory()->fromApp()->count(2)->create(['evoliz_code' => null, 'company_name' => 'SARL DE L EXEMPLE', 'postcode' => '22100']);

    $report = ($this->import)("code;societe;code_postal\nC00001;SARL DE L EXEMPLE;22100\n");

    expect($report)->toMatchArray(['created' => 1, 'updated' => 0, 'warnings' => 1])
        ->and(Client::query()->whereNull('evoliz_code')->count())->toBe(2)
        ->and(Client::query()->where('evoliz_code', 'C00001')->value('source'))->toBe(ClientSource::Import);
});

it('does not adopt across postcodes, an imported client, or a typed client that has a code', function () {
    Client::factory()->fromApp()->create(['evoliz_code' => null, 'company_name' => 'SARL A', 'postcode' => '06999']);
    Client::factory()->fromApp()->create(['evoliz_code' => 'OTHER', 'company_name' => 'SARL A', 'postcode' => '22100']);
    Client::factory()->imported()->create(['company_name' => 'SARL A', 'postcode' => '22100']);

    $report = ($this->import)("code;societe;code_postal\nC00001;SARL A;22100\n");

    expect($report['created'])->toBe(1);
});

it('finds a typed client an admin gave a code, which keeps its source', function () {
    $typed = Client::factory()->fromApp()->create(['evoliz_code' => 'C00009', 'contact_name' => null]);

    expect(($this->import)("code;contact\nC00009;Someone\n")['updated'])->toBe(1);

    $typed->refresh();
    expect($typed->contact_name)->toBe('Someone')->and($typed->source)->toBe(ClientSource::App);
});

it('warns about a postcode or mobile out of format and stores them', function () {
    $report = ($this->import)("code;societe;code_postal;mobile\nC00001;X;B-1000;09 70 00 00 00\n");

    expect($report)->toMatchArray(['created' => 1, 'warnings' => 2]);
    $client = Client::query()->sole();
    expect($client->postcode)->toBe('B-1000')->and($client->mobile)->toBe('09 70 00 00 00');
});

it('warns about an invalid email and ignores the field', function () {
    $client = Client::factory()->imported()->create(['evoliz_code' => 'C00001', 'email' => 'kept@example.test']);

    $report = ($this->import)("code;email\nC00001;not an email\n");

    expect($report['warnings'])->toBe(1)->and($client->refresh()->email)->toBe('kept@example.test');
});

it('rejects a row missing a company on create or holding a value too long, and the rest continue', function () {
    $long = str_repeat('x', 121);
    $report = ($this->import)("code;societe;contact\nC1;;\nC2;OK;{$long}\nC3;OK;\n;NO CODE;\n");

    expect($report)->toMatchArray(['created' => 1, 'errors' => 3])
        ->and(Client::query()->sole()->evoliz_code)->toBe('C3');
});

it('lets the first row win when a code appears twice', function () {
    $report = ($this->import)("code;societe\nC1;FIRST\nc1;SECOND\n");

    expect($report)->toMatchArray(['created' => 1, 'errors' => 1])
        ->and(Client::query()->sole()->company_name)->toBe('FIRST');
});

it('rejects the file without a code column', function () {
    ($this->import)("societe;ville\nX;Y\n");
})->throws(CsvRejected::class);

it('writes nothing on a dry run', function () {
    $typed = Client::factory()->fromApp()->create(['evoliz_code' => null, 'company_name' => 'SARL DE L EXEMPLE', 'postcode' => '22100']);

    $preview = ($this->import)($this->fixture, true);

    expect($preview)->toMatchArray(['created' => 1, 'updated' => 1])
        ->and(Client::query()->count())->toBe(1)
        ->and($typed->refresh()->evoliz_code)->toBeNull()
        ->and(ClientChange::query()->count())->toBe(0)
        ->and(ActivityLog::query()->count())->toBe(0);
});

it('logs one activity entry with the counts and no personal data', function () {
    ($this->import)($this->fixture);

    $entry = ActivityLog::query()->sole();
    expect($entry->action)->toBe(ActivityAction::Imported)
        ->and($entry->meta_json)->toMatchArray(['kind' => 'clients', 'created' => 2]);
    expect(json_encode($entry->meta_json))->not->toContain('EXEMPLE')->not->toContain('C00001');
});

it('feeds the search of the order form', function () {
    ($this->import)($this->fixture);

    Pest\Laravel\actingAs($this->admin)->getJson('/api/clients/search?q=exempleville')
        ->assertOk()
        ->assertJsonPath('data.0.company_name', 'SARL DE L EXEMPLE')
        ->assertJsonPath('data.0.linked', true);
});
