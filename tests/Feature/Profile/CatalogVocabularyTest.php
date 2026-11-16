<?php

use App\Domain\Catalog\ArticleClassifier;
use App\Domain\Catalog\ArticleImport;
use App\Domain\Catalog\Catalog;
use App\Domain\Catalog\CatalogExport;
use App\Domain\Catalog\NewArticles;
use App\Domain\Reports\StockReport;
use App\Domain\Reports\StockReportExport;
use App\Enums\AttributeSource;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\CategoryThreshold;
use App\Models\User;
use App\Support\Profile\Profile;
use App\Support\Profile\ProfileCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;
use function Pest\Laravel\putJson;

/*
| the catalogue's categories, groups and
| variants, the classifier's lexicon and the "new article" button come from the
| profile (catalog.*); with none, the neutral defaults: no category, one counting
| variant, no guessing, no button. Stored values are kept as they are. The rest of
| the suite runs on the pinned fixture, which keeps today's lists.
*/

/** config('passorde') as this fixture profile gives it (null: the neutral defaults). */
function withCatalogProfile(?string $name): void
{
    config()->set('passorde', Profile::load($name === null ? null : base_path("tests/Fixtures/profiles/{$name}")));
}

function fictionalArticle(string $reference, ?string $category, string $variant): Article
{
    $article = Article::factory()->create(['reference' => $reference, 'designation' => $reference, 'stock_qty' => 5, 'is_active' => true]);
    ArticleAttribute::query()->create(['article_id' => $article->id, 'category' => $category, 'variant' => $variant, 'needs_review' => false, 'source' => AttributeSource::Manual]);

    return $article;
}

describe('the lists', function () {
    it('has no category and one counting variant by default', function () {
        withCatalogProfile(null);

        expect(Catalog::categories())->toBe([])
            ->and(Catalog::variantValues())->toBe(['standard'])
            ->and(Catalog::defaultVariant())->toBe('standard')
            ->and(Catalog::counts('standard'))->toBeTrue()
            ->and(Catalog::categoryName())->toBe('catégorie')
            ->and(Catalog::needsReview(null, 'standard'))->toBeFalse();
    });

    it('reads categories, groups and variants from the profile; behaviour follows the variant settings', function () {
        withCatalogProfile('fictional');

        expect(Catalog::categoryValues())->toBe(['POMMIER', 'POIRIER', 'ERABLE'])
            ->and(Catalog::categoryLabel('ERABLE'))->toBe('Érable')
            ->and(Catalog::categoryGroup('POIRIER'))->toBe('fruitiers')
            ->and(Catalog::groupLabel('ornement'))->toBe('ORNEMENT')
            ->and(Catalog::variantGroups())->toBe(['outils'])
            ->and(Catalog::countingVariants())->toBe(['racine', 'godet'])
            ->and(Catalog::defaultVariant())->toBe('racine')
            ->and(Catalog::variant('godet')['name'])->toBe('Godet')
            ->and(Catalog::findCategory('érable'))->toBe('ERABLE')
            ->and(Catalog::findVariant('GODET'))->toBe('godet')
            ->and(Catalog::findCategory('CHENE'))->toBeNull()
            ->and(Catalog::needsReview(null, 'racine'))->toBeTrue()
            ->and(Catalog::needsReview(null, 'outil'))->toBeFalse()
            ->and(Catalog::leftOutOfStock('outil'))->toBeFalse();
    });

    it('keeps a stored value the profile no longer lists, without failing', function () {
        withCatalogProfile('fictional');

        expect(Catalog::categoryLabel('PLATANE'))->toBe('PLATANE')
            ->and(Catalog::categoryGroup('PLATANE'))->toBeNull()
            ->and(Catalog::variantLabel('motte'))->toBe('motte')
            ->and(Catalog::counts('motte'))->toBeFalse();
    });
});

describe('the classifier', function () {
    it('guesses nothing without a lexicon', function () {
        withCatalogProfile(null);

        expect(ArticleClassifier::classify('POMMIER GODET', 'POMMIER GALA EN GODET', 'OUT'))
            ->toBe(['category' => null, 'variant' => 'standard', 'needs_review' => false]);
    });

    it('uses the profile lexicon', function (string $reference, ?string $code, ?string $category, string $variant, bool $review) {
        withCatalogProfile('fictional');

        expect(ArticleClassifier::classify($reference, '', $code))->toBe(['category' => $category, 'variant' => $variant, 'needs_review' => $review]);
    })->with([
        'a word' => ['POMMIER GALA', null, 'POMMIER', 'racine', false],
        'a truncated word' => ['POMMI GALA', null, 'POMMIER', 'racine', false],
        'one typo' => ['POMIMER GALA', null, 'POMMIER', 'racine', false],
        'an alias and a variant word' => ['POM GALA GODET', null, 'POMMIER', 'godet', false],
        'a phrase' => ['ERABLE DU JAPON', null, 'ERABLE', 'racine', false],
        'two categories' => ['POMMIER POIRIER', null, null, 'racine', true],
        'a variant of its own tab' => ['SECATEUR', null, null, 'outil', false],
        'a classification code' => ['POMMIER GALA', 'OUT', null, 'outil', false],
        'nothing known' => ['TILLEUL', null, null, 'racine', true],
    ]);

    it('applies forced words, secondary categories and weak codes', function () {
        withCatalogProfile('fictional');
        config()->set('passorde.catalog.classifier.forced_variant_words', ['outil' => [['KIT', 'GREFFE']]]);
        config()->set('passorde.catalog.classifier.secondary', ['POIRIER']);
        config()->set('passorde.catalog.classifier.weak_classification_codes', ['20' => 'outil']);

        expect(ArticleClassifier::classify('KIT DE GREFFE POMMIER', '')['variant'])->toBe('outil')
            ->and(ArticleClassifier::classify('KIT POMMIER', '')['variant'])->toBe('racine')
            ->and(ArticleClassifier::classify('POMMIER POIRIER', '')['category'])->toBe('POMMIER')
            ->and(ArticleClassifier::classify('TILLEUL', '', '20')['variant'])->toBe('outil')
            ->and(ArticleClassifier::classify('POMMIER', '', '20')['variant'])->toBe('racine');
    });
});

describe('the new article button', function () {
    beforeEach(function () {
        fileMode();
        $this->seller = User::factory()->create();
    });

    it('is not there without catalog.new_article: the API answers 404', function () {
        withCatalogProfile(null);

        expect(NewArticles::enabled())->toBeFalse()->and(NewArticles::label())->toBeNull()
            ->and(NewArticles::lacksNumbers('ANYTHING'))->toBeFalse();
        actingAs($this->seller);
        putJson('/api/article-requests/'.Str::uuid(), ['name' => 'GALA', 'category' => 'X', 'variant' => 'standard', 'unit_price_ht' => '10'])->assertNotFound();
        getJson('/api/categories')->assertOk()->assertExactJson(['data' => []]);
    });

    it('builds reference, designation and VAT from the profile templates', function () {
        withCatalogProfile('fictional');
        actingAs($this->seller);

        putJson('/api/article-requests/'.Str::uuid(), ['name' => 'gala', 'category' => 'POMMIER', 'variant' => 'godet', 'unit_price_ht' => '12.50'])
            ->assertCreated()
            ->assertJsonPath('request.article.reference', 'GALA GD POM');

        $article = Article::query()->where('reference', 'GALA GD POM')->sole();
        expect($article->designation)->toBe('GALA GODET POMMIER')
            ->and((float) $article->vat_rate)->toBe(10.0)
            ->and($article->attribute->category)->toBe('POMMIER')
            ->and(NewArticles::reference('GALA', 'ERABLE', 'racine'))->toBe('GALA ERA')
            ->and(NewArticles::label())->toBe('Nouvelle variété');
        getJson('/api/categories')->assertOk()->assertJsonPath('data.2', ['value' => 'ERABLE', 'label' => 'Érable']);
    });

    it('refuses a variant that does not count', function () {
        withCatalogProfile('fictional');
        actingAs($this->seller);

        putJson('/api/article-requests/'.Str::uuid(), ['name' => 'GALA', 'category' => 'POMMIER', 'variant' => 'outil', 'unit_price_ht' => '10'])
            ->assertUnprocessable()->assertJsonValidationErrors('variant');
    });
});

describe('import and exports', function () {
    it('builds the template row from the profile', function (?string $profile, array $row) {
        withCatalogProfile($profile);

        expect(ArticleImport::templateRow())->toBe($row);
    })->with([
        'neutral' => [null, ['EXEMPLE', 'ARTICLE EXEMPLE', '25,00', '20', '10', 'oui', 'oui', 'Fournisseur Exemple', '', '', 'standard', '5']],
        'fictional' => ['fictional', ['EXEMPLE', 'ARTICLE EXEMPLE', '25,00', '20', '10', 'oui', 'oui', 'Fournisseur Exemple', '', 'POMMIER', 'racine', '5']],
    ]);

    it('imports categories and variants as the profile lists them, under the neutral column names too', function () {
        withCatalogProfile('fictional');
        fileMode();

        app(ArticleImport::class)->run("reference;designation;prix_ht;tva;catégorie;variante\nGALA;Gala;10;10;pommier;Godet\nTILLEUL;Tilleul;10;10;tilleul;motte\n", User::factory()->admin()->create(), false);

        $attribute = fn (string $reference) => Article::query()->where('reference', $reference)->sole()->attribute;
        expect($attribute('GALA')->category)->toBe('POMMIER')
            ->and($attribute('GALA')->variant)->toBe('godet')
            ->and($attribute('TILLEUL')->category)->toBeNull()
            ->and($attribute('TILLEUL')->variant)->toBe('racine')
            ->and($attribute('TILLEUL')->needs_review)->toBeTrue();
    });

    it('names the category and variant columns from the profile, and always accepts the neutral names', function () {
        withCatalogProfile(null);
        expect(array_slice(ArticleImport::columns(), -3))->toBe(['categorie', 'variante', 'seuil'])
            ->and(ArticleImport::aliases())->not->toHaveKey('categorie');

        config()->set('passorde.catalog.csv', ['category' => 'essence', 'variant' => 'conditionnement']);
        expect(array_slice(ArticleImport::columns(), -3))->toBe(['essence', 'conditionnement', 'seuil'])
            ->and(ArticleImport::aliases())->toMatchArray(['categorie' => 'essence', 'variante' => 'conditionnement']);
    });

    it('refuses CSV column names that clash', function () {
        $directory = sys_get_temp_dir().'/passorde-csv-'.Str::random(6);
        mkdir($directory);
        file_put_contents("{$directory}/profile.php", '<?php return '.var_export(['catalog' => ['csv' => ['category' => 'tva', 'variant' => 'Type']]], true).';');

        expect(ProfileCheck::run($directory, false)['errors'])->toContain('catalog.csv.category "tva" is already a column of the articles CSV.')
            ->toContain('catalog.csv.variant must be a CSV column name in lower case (letters, digits, _).');
    });

    it('heads the catalogue export with what the profile calls a category, and shows the labels', function () {
        withCatalogProfile('fictional');
        config()->set('passorde.catalog.category_name', ['singular' => 'essence', 'plural' => 'essences']);
        fictionalArticle('GALA GD POM', 'ERABLE', 'godet');

        $sheet = app(CatalogExport::class)->spreadsheet()->getActiveSheet();

        expect($sheet->getCell('F1')->getValue())->toBe('Essence')
            ->and($sheet->getCell('F2')->getValue())->toBe('Érable')
            ->and($sheet->getCell('G2')->getValue())->toBe('Godet');
    });

    it('orders the stock tabs from the profile: the variants\' own tabs first, then the category groups', function () {
        withCatalogProfile('fictional');
        fictionalArticle('GALA POM', 'POMMIER', 'racine');
        fictionalArticle('SECATEUR', null, 'outil');

        $report = app(StockReport::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-11-14'));
        $tabs = collect($report['tabs'])->keyBy('key');
        $book = app(StockReportExport::class)->spreadsheet($report);

        expect(array_column($report['tabs'], 'key'))->toBe(['fruitiers', 'ornement', 'outils', StockReport::UNCLASSIFIED, StockReport::BROKEN, StockReport::GIFTS, StockReport::MATERIAL_GIFTS])
            ->and(array_column($tabs['fruitiers']['rows'], 'reference'))->toBe(['GALA POM'])
            ->and(array_column($tabs['outils']['rows'], 'reference'))->toBe(['SECATEUR'])
            ->and($book->getSheet(0)->getTitle())->toBe('OUTILS')
            ->and($book->getSheet(1)->getTitle())->toBe('FRUITIERS')
            ->and($book->getActiveSheetIndex())->toBe(1);
    });
});

describe('edge cases', function () {
    it('puts an article whose category or variant group the profile does not list under "À CLASSER"', function () {
        withCatalogProfile('fictional');
        config()->set('passorde.catalog.categories', [...config('passorde.catalog.categories'), ['value' => 'TILLEUL', 'label' => 'Tilleul', 'group' => 'perdu']]);
        config()->set('passorde.catalog.variants', [...config('passorde.catalog.variants'), ['value' => 'kit', 'label' => 'Kit', 'counts' => false, 'group' => 'disparu']]);
        fictionalArticle('TILLEUL ART', 'TILLEUL', 'racine');
        fictionalArticle('KIT ART', null, 'kit');

        $tabs = collect(app(StockReport::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-11-14'))['tabs'])->keyBy('key');

        expect(array_column($tabs[StockReport::UNCLASSIFIED]['rows'], 'reference'))->toBe(['KIT ART', 'TILLEUL ART']);
    });

    it('shows a threshold stored for a category no longer in the profile, and lets it be cleared only', function () {
        withCatalogProfile('fictional');
        $admin = User::factory()->admin()->create();
        CategoryThreshold::query()->create(['category' => 'PLATANE', 'threshold' => 5]);
        CategoryThreshold::query()->create(['category' => 'TILLEUL', 'threshold' => 3]);

        actingAs($admin)->get('/admin/category-thresholds')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('categories.3', ['category' => 'PLATANE', 'group' => 'Hors catalogue', 'threshold' => 5, 'articles' => 0, 'orphan' => true])
            ->missing('categories.0.orphan')
            ->where('categories.4.category', 'TILLEUL'));

        put('/admin/category-thresholds', ['thresholds' => ['PLATANE' => null, 'TILLEUL' => 9, 'CHENE' => 4, 'POMMIER' => 12]])->assertRedirect();

        expect(CategoryThreshold::query()->orderBy('category')->pluck('threshold', 'category')->all())->toBe(['POMMIER' => 12, 'TILLEUL' => 3]);
    });
});

describe('the profile check', function () {
    it('checks the new catalogue keys', function () {
        $directory = sys_get_temp_dir().'/passorde-catalog-'.Str::random(6);
        mkdir($directory);
        file_put_contents("{$directory}/profile.php", '<?php return '.var_export(['catalog' => [
            'categories' => [['value' => 'POMMIER', 'label' => 'Pommier', 'group' => 'fruitiers']],
            'category_groups' => [['key' => 'fruitiers', 'label' => 'FRUITIERS']],
            'category_name' => ['singular' => '', 'plural' => 'essences'],
            'variants' => [['value' => 'racine', 'label' => 'Racine', 'name' => '', 'counts' => true, 'group' => null]],
            'import_example' => ['reference' => 'X', 'designation' => 'Y', 'vat' => 'vingt', 'category' => 'CHENE', 'variant' => 'motte'],
            'classifier' => ['secondary' => ['CHENE'], 'forced_variant_words' => ['motte' => ['MOTTE']], 'weak_classification_codes' => ['20' => 'tige']],
            'new_article' => ['label' => 'variété', 'vat' => 10, 'reference' => '{name}', 'designation' => '{name}', 'complete_pattern' => '/(/'],
        ]], true).';');

        $errors = ProfileCheck::run($directory, false)['errors'];

        expect($errors)->toContain('catalog.category_name.singular is required (a text).')
            ->toContain('catalog.variants[0].name is required (a text).')
            ->toContain('catalog.import_example.vat must be a rate, e.g. 20.')
            ->toContain('catalog.import_example.category is not in catalog.categories.')
            ->toContain('catalog.import_example.variant is not in catalog.variants.')
            ->toContain('catalog.classifier.secondary names "CHENE", which is not in catalog.categories.')
            ->toContain('catalog.classifier.forced_variant_words "motte" is not in catalog.variants.')
            ->toContain('catalog.classifier.weak_classification_codes "20" names "tige", neither a category nor a variant.')
            ->toContain('catalog.new_article.complete_pattern must be a regular expression, e.g. "/\\bLOT\\b/i", or null.');
    });

    it('refuses a group key that is the key of a fixed stock tab', function () {
        $directory = sys_get_temp_dir().'/passorde-catalog-'.Str::random(6);
        mkdir($directory);
        file_put_contents("{$directory}/profile.php", '<?php return '.var_export(['catalog' => [
            'categories' => [['value' => 'POMMIER', 'label' => 'Pommier', 'group' => 'gifts']],
            'category_groups' => [['key' => 'gifts', 'label' => 'CADEAUX']],
        ]], true).';');

        expect(ProfileCheck::run($directory, false)['errors'])->toContain('catalog.category_groups[0].key "gifts" is the key of a fixed stock tab: choose another.');
    });

    it('accepts the pinned and fictional profiles', function (string $name) {
        fileMode();

        expect(ProfileCheck::run(base_path("tests/Fixtures/profiles/{$name}"), false)['errors'])->toBe([]);
    })->with(['pinned', 'fictional']);
});
