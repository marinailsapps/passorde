<?php

use App\Domain\Clients\ClientChanges;
use App\Domain\Clients\ClientImport;
use App\Domain\Orders\OrderExport;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use App\Support\Profile\Profile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
| the optional client field (profile
| client.extra_field) gives its label, CSV column and search toggle; off, it is
| not shown, imported, exported or searched. Its value stays `extra_field`
| in the database and the API. The rest of the suite runs on the pinned
| fixture, which keeps today's field.
*/

/** config('passorde') as this fixture profile gives it (null: the neutral defaults). */
function withClientProfile(?string $name): void
{
    config()->set('passorde', Profile::load($name === null ? null : base_path("tests/Fixtures/profiles/{$name}")));
}

beforeEach(function () {
    $this->seller = User::factory()->create();
    $this->client = Client::factory()->create(['company_name' => 'SARL DU VAL', 'town' => 'LAMBERTOU', 'extra_field' => 'NP-58321']);
});

it('shares the field with the pages, or null when the profile has none', function (?string $profile, ?array $expected) {
    withClientProfile($profile);

    actingAs($this->seller)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('clientExtraField', $expected));
})->with([
    'no profile' => [null, null],
    'fictional' => ['fictional', ['label' => 'N° adhérent', 'searchable' => true]],
]);

it('shares today\'s field with the pinned profile', function () {
    actingAs($this->seller)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('clientExtraField', ['label' => 'N° adhérent', 'searchable' => true]));
});

it('searches the field only when the profile says so', function (?string $profile, array $found) {
    withClientProfile($profile);
    if ($profile === 'not searched') {
        withClientProfile('fictional');
        config()->set('passorde.client.extra_field.searchable', false);
    }

    expect(actingAs($this->seller)->getJson('/api/clients/search?q=5832')->assertOk()->json('data.*.company_name'))->toBe($found)
        // The value still travels in the answers, whatever the profile.
        ->and(getJson('/api/clients/search?q=lambertou')->json('data.0.extra_field'))->toBe('NP-58321');
})->with([
    'fictional, searched' => ['fictional', ['SARL DU VAL']],
    'fictional, not searched' => ['not searched', []],
    'no profile' => [null, []],
]);

it('names the import column after the profile, before "actif", or leaves it out', function () {
    withClientProfile(null);
    expect(ClientImport::columns())->toBe(ClientImport::BASE_COLUMNS)->not->toContain('numero_adherent')
        ->and(ClientImport::templateRow())->toHaveCount(count(ClientImport::BASE_COLUMNS));

    withClientProfile('fictional');
    expect(array_slice(ClientImport::columns(), -2))->toBe(['numero_adherent', 'actif'])
        ->and(ClientImport::templateRow())->toHaveCount(count(ClientImport::BASE_COLUMNS) + 1);

    $report = app(ClientImport::class)->run("code;societe;code_postal;ville;numero_adherent\nC1;EURL TEST;87100;MONTFICTIF;A-42\n", User::factory()->admin()->create(), false)->toArray();

    expect($report)->toMatchArray(['created' => 1, 'errors' => 0])
        ->and(Client::query()->where('evoliz_code', 'C1')->value('extra_field'))->toBe('A-42');
});

it('ignores the column with no field in the profile', function () {
    fileMode();
    withClientProfile(null);

    $report = app(ClientImport::class)->run("code;societe;code_postal;ville;numero_adherent\nC2;EURL TEST;87100;MONTFICTIF;FR1\n", User::factory()->admin()->create(), false)->toArray();

    expect($report)->toMatchArray(['created' => 1])
        ->and($report['file_warnings'])->not->toBe([])
        ->and(Client::query()->where('evoliz_code', 'C2')->value('extra_field'))->toBeNull();
});

it('heads the export column with the field\'s label, or leaves it out', function () {
    fileMode();
    $article = Article::factory()->imported()->create();
    $order = Order::factory()->for($this->client)->status(OrderStatus::Approved)->withLines([[$article, 1]])->create();
    $rows = fn () => app(OrderExport::class)->rows([$order->fresh()]);

    withClientProfile('fictional');
    $columns = array_column(OrderExport::columns(), 0);
    expect($columns)->toContain('N° adhérent')
        ->and($rows()[0][array_search('N° adhérent', $columns, true)])->toBe('NP-58321');

    withClientProfile(null);
    expect(OrderExport::columns())->toHaveCount(count($columns) - 1)
        ->and($rows()[0])->toHaveCount(count($columns) - 1)
        ->and($rows()[0])->not->toContain('NP-58321');
});

it('names the field by its label in validation errors and in the change history', function () {
    withClientProfile('fictional');

    actingAs($this->seller)->putJson('/api/clients/'.Str::uuid(), [
        'company_name' => 'EURL TEST', 'postcode' => '87100', 'town' => 'MONTFICTIF', 'extra_field' => str_repeat('9', 51),
    ])->assertStatus(422)->assertJsonPath('errors.extra_field.0', fn (string $message) => str_contains($message, 'N° adhérent'));

    expect(ClientChanges::labels(['town', 'extra_field']))->toBe('ville, n° adhérent');
});

it('shows the field on the admin client page only when the profile has one', function (?string $profile, bool $shown) {
    withClientProfile($profile);

    actingAs(User::factory()->admin()->create())->get("/admin/clients/{$this->client->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clientExtraField', $shown ? ['label' => 'N° adhérent', 'searchable' => true] : null));
})->with([
    'fictional' => ['fictional', true],
    'no profile' => [null, false],
]);
