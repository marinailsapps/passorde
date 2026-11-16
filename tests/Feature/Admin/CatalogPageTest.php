<?php

use App\Enums\AttributeSource;
use App\Enums\SyncKind;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    Article::factory()->create(['reference' => 'ASTRA MT POM', 'designation' => 'ASTRA MOTTE POMMIER / CAL : 12-14']);
    Article::factory()->create(['reference' => 'TOPAZE POM', 'designation' => 'TOPAZE POMMIER / PG BA29']);
    Article::factory()->create(['reference' => 'BRAVO TOM POM', 'designation' => 'BRAVO TOM POMMIER', 'is_active' => false]);
});

/**
 * @return list<string>
 */
function searchCatalog(string $query): array
{
    $references = [];

    actingAs(test()->admin)->get('/catalog?'.http_build_query(['q' => $query]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$references) {
            $references = array_column($page->toArray()['props']['articles']['data'], 'reference');
        });

    return $references;
}

it('searches the catalog by', function (string $query, array $expected) {
    expect(searchCatalog($query))->toBe($expected);
})->with([
    'reference' => ['topaze', ['TOPAZE POM']],
    'designation' => ['cal', ['ASTRA MT POM']],
    'shared word' => ['pommier', ['ASTRA MT POM', 'TOPAZE POM']],
    'one character is ignored' => ['a', ['ASTRA MT POM', 'TOPAZE POM']],
    'LIKE wildcards are literal' => ['%_', []],
]);

it('shows price, VAT and stock', function () {
    actingAs($this->admin)->get('/catalog?q=astra')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('articles.data.0', fn (AssertableInertia $article) => $article
                ->hasAll(['unit_price_ht', 'vat_rate', 'stock_qty', 'is_stocked', 'synced_at'])
                ->etc()));
});

it('queues an article sync', function () {
    Queue::fake();

    actingAs($this->admin)->post('/admin/catalog/sync')->assertRedirect();

    Queue::assertPushed(SyncJob::class, fn (SyncJob $job) => $job->run->kind === SyncKind::Articles);
});

it('downloads the clients\' price list as Excel, for every role', function () {
    $above = Article::query()->where('reference', 'ASTRA MT POM')->sole();
    $above->update(['unit_price_ht' => 53, 'vat_rate' => 5.5, 'is_stocked' => true, 'stock_qty' => 7]);
    ArticleAttribute::query()->create(['article_id' => $above->id, 'category' => 'POMMIER', 'variant' => 'motte', 'needs_review' => false, 'source' => AttributeSource::Auto]);

    $response = actingAs(User::factory()->create())->get('/catalog.xlsx')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('tarifs-');
    file_put_contents($path = tempnam(sys_get_temp_dir(), 'xlsx'), $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();
    unlink($path);

    // Prices only (no stock or Evoliz internals), active articles only, by reference.
    expect($sheet->rangeToArray('A1:H1', null, false, false)[0])->toBe(['Référence', 'Désignation', 'PU HT', 'TVA %', 'PU TTC', 'Essence', 'Type', null])
        ->and([$sheet->getCell('A2')->getValue(), $sheet->getCell('A3')->getValue()])->toBe(['ASTRA MT POM', 'TOPAZE POM'])
        ->and($sheet->rangeToArray('C2:G2', null, false, false)[0])->toBe([53, 5.5, '=ROUND(C2*(1+D2/100),2)', 'POMMIER', 'MOTTE'])
        ->and(round($sheet->getCell('E2')->getCalculatedValue(), 2))->toEqual(55.92)
        ->and($sheet->getCell('A4')->getValue())->toBe('2 articles');
});

it('shows the catalogue to sales people without the sync bar, and sends old admin links there', function () {
    actingAs(User::factory()->create())->get('/catalog')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Index')->where('sync', null)->has('articles.data', 2));
    actingAs(User::factory()->create())->post('/admin/catalog/sync')->assertForbidden();

    actingAs($this->admin)->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->whereNot('sync', null));
    actingAs($this->admin)->get('/admin/catalog')->assertRedirect('/catalog');
});
