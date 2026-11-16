<?php

use App\Domain\Import\CsvReader;
use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->csv = fn (string $contents) => UploadedFile::fake()->createWithContent('import.csv', $contents);
});

function importTemplate(string $kind): string
{
    return get("/admin/import/templates/{$kind}.csv")->assertOk()->streamedContent();
}

it('is refused in Evoliz mode', function () {
    actingAs($this->admin);

    get('/admin/import')->assertForbidden();
    get('/admin/import/templates/articles.csv')->assertForbidden();
    postJson('/admin/import/articles', ['file' => ($this->csv)("reference\nX\n"), 'dry_run' => '0'])->assertForbidden();

    expect(Article::query()->count())->toBe(0);
});

it('is for admins only', function () {
    fileMode();
    actingAs(User::factory()->create());

    get('/admin/import')->assertForbidden();
    postJson('/admin/import/articles', ['file' => ($this->csv)("reference\nX\n"), 'dry_run' => '0'])->assertForbidden();
});

it('renders the page with the columns', function () {
    fileMode();
    actingAs($this->admin);

    get('/admin/import')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Admin/Import/Index')
        ->where('columns.articles.0', 'reference')
        ->where('columns.clients.0', 'code')
        ->where('maxMegabytes', 5));
});

it('previews a dry run, then applies the same upload', function () {
    fileMode();
    actingAs($this->admin);
    $csv = "reference;designation;prix_ht;tva\nALPHA;ALPHA POMMIER;12,5;5,5\nBAD;;;\n";

    post('/admin/import/articles', ['file' => ($this->csv)($csv), 'dry_run' => '1'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'errors' => 1])
        ->assertJsonPath('lines.1.line', 3);
    expect(Article::query()->count())->toBe(0);

    post('/admin/import/articles', ['file' => ($this->csv)($csv), 'dry_run' => '0'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['created' => 1, 'errors' => 1]);
    expect(Article::query()->count())->toBe(1);
});

it('answers 422 with the reason when the file is rejected', function () {
    fileMode();
    actingAs($this->admin);

    postJson('/admin/import/clients', ['file' => ($this->csv)("societe\nX\n"), 'dry_run' => '1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    postJson('/admin/import/clients', ['file' => UploadedFile::fake()->create('big.csv', CsvReader::MAX_BYTES / 1024 + 1), 'dry_run' => '1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('stores nothing about the file in the session', function () {
    fileMode();
    actingAs($this->admin);

    $response = post('/admin/import/clients', ['file' => ($this->csv)("code;societe\nC1;SECRET SA\n"), 'dry_run' => '0'], ['Accept' => 'application/json']);

    $response->assertOk();
    expect(json_encode(session()->all()))->not->toContain('SECRET');
});

it('serves templates that import cleanly into a working order form, then change nothing', function () {
    fileMode();
    actingAs($this->admin);

    $articles = importTemplate('articles');
    $clients = importTemplate('clients');

    expect($articles)->toStartWith("\xEF\xBB\xBFreference;designation;prix_ht;tva;stock;gestion_stock;actif;fournisseur;classification;essence;type;seuil\n")
        ->and($clients)->toStartWith("\xEF\xBB\xBFcode;societe;contact;adresse;code_postal;ville;telephone;mobile;email;numero_adherent;actif\n");

    foreach (['articles' => $articles, 'clients' => $clients] as $kind => $contents) {
        postJson("/admin/import/{$kind}", ['file' => ($this->csv)($contents), 'dry_run' => '1'])
            ->assertOk()->assertJson(['created' => 1, 'errors' => 0, 'warnings' => 0]);
        postJson("/admin/import/{$kind}", ['file' => ($this->csv)($contents), 'dry_run' => '0'])
            ->assertOk()->assertJson(['created' => 1, 'errors' => 0]);
        postJson("/admin/import/{$kind}", ['file' => ($this->csv)($contents), 'dry_run' => '0'])
            ->assertOk()->assertJson(['created' => 0, 'updated' => 0, 'unchanged' => 1]);
    }

    getJson('/api/articles/search?q=exemple')->assertOk()->assertJsonPath('data.0.reference', 'EXEMPLE MT POM');
    getJson('/api/clients/search?q=exemple')->assertOk()->assertJsonPath('data.0.company_name', "SARL DE L'EXEMPLE");
    expect(Client::query()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
