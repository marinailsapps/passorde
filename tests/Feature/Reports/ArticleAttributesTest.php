<?php

use App\Domain\Catalog\ArticleAttributes;
use App\Enums\AttributeSource;
use App\Enums\SyncKind;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\CategoryThreshold;
use App\Models\SyncRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * A small workbook: master list, MATERIEL, a category tab, and a sheet without a category column.
 */
function stockFile(): string
{
    $book = new Spreadsheet;
    $master = $book->getActiveSheet()->setTitle('STOCK AU 03 05 23');
    $master->fromArray([
        ['Référence', 'Désignation', 'LOT', 'PORTE-GREFFE', 'Variante', 'Catégorie', 'DISPONIBILITE'],
        ['ilaria ', 'ILARIA / PG : M106', null, null, 'NU', 'ARBRES DIVERS', 'OK'],
        ['RIVO_ABR', 'RIVO ABR', null, null, 'NU', 'ABRICOTIERS', 'OK'],
        ['LUNA  TIGE', 'LUNA GRIOTTE', null, null, 'MOTXE', 'POMNIER', 'OK'],
        ['NOT IN EVOLIZ', 'OLD VARIETY', null, null, 'NU', 'POMMIER', 'FINI'],
        ['IRIS TIGE POMMIER', 'IRIS', null, null, 'NU', null, 'OK'],
    ]);
    $book->createSheet()->setTitle('MATERIEL')->fromArray([
        ['Référence', 'Désignation', 'LOT', 'PORTE-GREFFE', 'CALIBRE', 'HAUTEUR', 'Catégorie'],
        ['GANTS', 'GANTS JARDIN', null, null, null, null, 'MATERIEL'],
    ]);
    $book->createSheet()->setTitle('AUTRES ESSENCES')->fromArray([
        ['Référence', 'Variante', 'Catégorie', 'Qté Stock Réel'],
        ['GINO FRAMBOISIEK', null, 'FRAMBOISIEK', 0],
    ]);
    $book->createSheet()->setTitle('PLANTS ABIMES')->fromArray([['Référence', 'RANG', 'QTE'], ['ILARIA', null, 2]]);

    $path = tempnam(sys_get_temp_dir(), 'stock').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

beforeEach(function () {
    $this->ilaria = Article::factory()->create(['reference' => 'ILARIA', 'designation' => 'ILARIA / PG : M106', 'designation_raw' => null]);
    $this->rivo = Article::factory()->create(['reference' => 'RIVO_ABR', 'designation' => 'RIVO ABR']);
    $this->luna = Article::factory()->create(['reference' => 'LUNA TIGE', 'designation' => 'LUNA GRIOTTE', 'sale_classification_code' => 'POMMIER']);
    $this->iris = Article::factory()->create(['reference' => 'IRIS TIGE POMMIER', 'designation' => 'IRIS TIGE POMMIER']);
    $this->gants = Article::factory()->create(['reference' => 'GANTS', 'designation' => 'GANTS JARDIN boîte de 100']);
    $this->gino = Article::factory()->create(['reference' => 'GINO FRAMBOISIEK', 'designation' => 'GINO']);
    $this->other = Article::factory()->create(['reference' => 'MYSTERE', 'designation' => 'MYSTERE / CAL : 12-14']);
    $this->file = stockFile();
});

afterEach(fn () => @unlink($this->file));

function attributeOf(Article $article): ArticleAttribute
{
    return ArticleAttribute::query()->where('article_id', $article->id)->sole();
}

it('classifies every article, then applies the file where the reference matches', function () {
    artisan('reports:seed-attributes', ['--file' => $this->file])
        ->assertSuccessful()
        ->expectsOutputToContain('7 active articles: 2 automatic, 5 from the file, 0 set by hand; 1 to review')
        ->expectsOutputToContain('6 references read, 5 applied, 1 not in the active catalogue');

    expect(attributeOf($this->ilaria))->category->toBe('AUTRE')->source->toBe(AttributeSource::File)->needs_review->toBeFalse()
        ->and(attributeOf($this->rivo))->category->toBe('ABRICOTIER')->variant->toBe('nu')
        ->and(attributeOf($this->luna))->category->toBe('POMMIER')->variant->toBe('motte')
        ->and(attributeOf($this->iris))->category->toBe('POMMIER')->source->toBe(AttributeSource::Auto)
        ->and(attributeOf($this->gants))->category->toBeNull()->variant->toBe('material')
        ->and(attributeOf($this->gino))->category->toBe('FRAMBOISIER')
        ->and(attributeOf($this->other))->category->toBeNull()->needs_review->toBeTrue()->source->toBe(AttributeSource::Auto);
});

it('gives the same result when run again, and never touches manual values', function () {
    artisan('reports:seed-attributes', ['--file' => $this->file])->assertSuccessful();
    attributeOf($this->rivo)->update(['category' => 'POIRIER', 'source' => AttributeSource::Manual, 'reorder_threshold' => 7]);
    $before = ArticleAttribute::query()->orderBy('id')->get(['id', 'category', 'variant', 'needs_review', 'source'])->toArray();

    artisan('reports:seed-attributes', ['--file' => $this->file])->assertSuccessful();

    expect(ArticleAttribute::query()->orderBy('id')->get(['id', 'category', 'variant', 'needs_review', 'source'])->toArray())->toBe($before)
        ->and(attributeOf($this->rivo))->category->toBe('POIRIER')->reorder_threshold->toBe(7);
});

it('classifies new articles during the catalogue sync and keeps manual values', function () {
    fakeEvoliz(['articles' => ['articles-page-1', 'articles-page-2']]);
    $run = SyncRun::queue(SyncKind::Articles, SyncTrigger::Console);
    SyncJob::dispatchSync($run);

    $synced = Article::query()->where('reference', 'B3Q3 POMMIER')->sole();
    expect(attributeOf($synced))->category->toBe('POMMIER')->source->toBe(AttributeSource::Auto);

    attributeOf($synced)->update(['category' => 'FIGUIER', 'source' => AttributeSource::Manual]);
    $synced->update(['designation' => 'changed in Evoliz']);
    $run = SyncRun::queue(SyncKind::Articles, SyncTrigger::Console);
    SyncJob::dispatchSync($run);

    expect(attributeOf($synced))->category->toBe('FIGUIER')->source->toBe(AttributeSource::Manual);
});

it('refreshes an automatic guess when Evoliz changes the designation', function () {
    $service = app(ArticleAttributes::class);
    $service->classify($this->other);
    expect(attributeOf($this->other))->needs_review->toBeTrue();

    $this->other->update(['designation' => 'MYSTERE GRENADIER']);
    $service->classify($this->other);

    expect(attributeOf($this->other))->category->toBe('GRENADIER')->needs_review->toBeFalse();
});

describe('admin screens', function () {
    beforeEach(function () {
        artisan('reports:seed-attributes', ['--file' => $this->file]);
        $this->admin = User::factory()->admin()->create();
    });

    it('lists the articles to check and filters by category and type', function () {
        actingAs($this->admin)->get('/admin/article-attributes?review=1')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/ArticleAttributes/Index')
                ->where('toReview', 1)
                ->has('articles.data', 1)
                ->where('articles.data.0.reference', 'MYSTERE'));

        actingAs($this->admin)->get('/admin/article-attributes?category=POMMIER&type=motte')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('articles.data', 1)->where('articles.data.0.reference', 'LUNA TIGE'));

        actingAs($this->admin)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('badges.to_review', 1));
    });

    it('saves an inline edit as manual and clears the review flag', function () {
        $attribute = attributeOf($this->other);

        actingAs($this->admin)->patch("/admin/article-attributes/{$attribute->id}", ['category' => 'GRENADIER', 'variant' => 'motte', 'reorder_threshold' => 12])
            ->assertRedirect();

        expect($attribute->fresh())->category->toBe('GRENADIER')->variant->toBe('motte')->reorder_threshold->toBe(12)
            ->needs_review->toBeFalse()->source->toBe(AttributeSource::Manual)->updated_by->toBe($this->admin->id);
    });

    it('sets one category on several articles, counted variants only', function () {
        actingAs($this->admin)->post('/admin/article-attributes/bulk-category', [
            'ids' => [attributeOf($this->other)->id, attributeOf($this->ilaria)->id, attributeOf($this->gants)->id],
            'category' => 'VIGNE',
        ])->assertRedirect()->assertSessionHas('success', '2 articles mis à jour.');

        expect(attributeOf($this->other)->category)->toBe('VIGNE')
            ->and(attributeOf($this->gants)->category)->toBeNull();
    });

    it('saves the thresholds per category, an empty one removes it', function () {
        CategoryThreshold::query()->create(['category' => 'GRENADIER', 'threshold' => 5]);

        actingAs($this->admin)->put('/admin/category-thresholds', ['thresholds' => ['POMMIER' => 20, 'GRENADIER' => null, 'NOT A CATEGORY' => 3]])->assertRedirect();

        expect(CategoryThreshold::query()->pluck('threshold', 'category')->all())->toBe(['POMMIER' => 20]);
        actingAs($this->admin)->get('/admin/category-thresholds')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('categories.0', ['category' => 'POMMIER', 'group' => 'POMMIER', 'threshold' => 20, 'articles' => 2]));
    });

    it('is for admins only', function () {
        actingAs(User::factory()->create());
        get('/admin/article-attributes')->assertForbidden();
        patch('/admin/article-attributes/'.attributeOf($this->other)->id, ['variant' => 'nu'])->assertForbidden();
        post('/admin/article-attributes/bulk-category', ['ids' => [1], 'category' => 'GRENADIER'])->assertForbidden();
        put('/admin/category-thresholds', ['thresholds' => []])->assertForbidden();
    });
});
