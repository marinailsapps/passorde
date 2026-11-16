<?php

use App\Domain\Catalog\ArticleImport;
use App\Domain\Import\CsvReader;
use App\Domain\Import\CsvRejected;
use App\Enums\ActivityAction;
use App\Enums\AttributeSource;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    fileMode();
    $this->admin = User::factory()->admin()->create();
    $this->import = fn (string $csv, bool $dryRun = false) => app(ArticleImport::class)->run($csv, $this->admin, $dryRun)->toArray();
});

function articleFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/import/{$name}"));
}

it('creates articles from the template format', function () {
    $report = ($this->import)(articleFixture('articles.csv'));

    expect($report)->toMatchArray(['created' => 3, 'updated' => 0, 'unchanged' => 0, 'errors' => 0]);

    $alpha = Article::query()->where('reference', 'ALPHA MT POM')->firstOrFail();
    expect($alpha->evoliz_article_id)->toBeNull()
        ->and($alpha->designation)->toBe('ALPHA MOTTE POMMIER PG MYROBOLAN LOT 0001')
        ->and($alpha->designation_raw)->toBeNull()
        ->and($alpha->unit_price_ht)->toBe('25.50')
        ->and($alpha->vat_rate)->toBe('5.50')
        ->and($alpha->stock_qty)->toBe('10.00')
        ->and($alpha->is_stocked)->toBeTrue()
        ->and($alpha->is_active)->toBeTrue()
        ->and($alpha->supplier_name)->toBe('Fournisseur Exemple');
});

it('defaults stock management from the stock column on create', function () {
    ($this->import)(articleFixture('articles.csv'));

    $beta = Article::query()->where('reference', 'BETA MON')->firstOrFail();
    expect($beta->is_stocked)->toBeFalse()->and($beta->stock_qty)->toBeNull()->and($beta->is_active)->toBeTrue();
});

it('changes nothing when the same file comes back, not even updated_at', function () {
    ($this->import)(articleFixture('articles.csv'));
    $before = Article::query()->orderBy('id')->pluck('updated_at', 'id')->map->toIso8601String();

    Carbon::setTestNow(now()->addHour());
    $report = ($this->import)(articleFixture('articles.csv'));
    Carbon::setTestNow();

    expect($report)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 3, 'errors' => 0])
        ->and($report['lines'])->toBe([])
        ->and(Article::query()->orderBy('id')->pluck('updated_at', 'id')->map->toIso8601String())->toEqual($before);
});

it('finds the article by reference, trimmed and in any case, and updates only what the file states', function () {
    $article = Article::factory()->imported()->create(['reference' => 'ALPHA MT POM', 'designation' => 'OLD', 'unit_price_ht' => 10, 'vat_rate' => 5.5, 'stock_qty' => 7, 'is_stocked' => true]);

    $report = ($this->import)("reference;prix_ht\n  alpha mt pom ;12,5\n");

    expect($report)->toMatchArray(['created' => 0, 'updated' => 1])
        ->and($report['lines'][0]['fields'])->toBe(['prix_ht']);
    $article->refresh();
    expect($article->unit_price_ht)->toBe('12.50')
        ->and($article->reference)->toBe('ALPHA MT POM')
        ->and($article->designation)->toBe('OLD')
        ->and($article->stock_qty)->toBe('7.00');
});

it('keeps the current value when a cell is empty', function () {
    $article = Article::factory()->imported()->create(['reference' => 'ALPHA', 'designation' => 'KEPT', 'supplier_name' => 'KEPT TOO', 'stock_qty' => 7, 'is_stocked' => true, 'is_active' => true]);

    ($this->import)("reference;designation;stock;gestion_stock;actif;fournisseur\nALPHA;;;;;\n");

    $article->refresh();
    expect($article->designation)->toBe('KEPT')
        ->and($article->supplier_name)->toBe('KEPT TOO')
        ->and($article->stock_qty)->toBe('7.00')
        ->and($article->is_stocked)->toBeTrue()
        ->and($article->is_active)->toBeTrue();
});

it('leaves rows absent from the file alone; actif = non deactivates', function () {
    $absent = Article::factory()->imported()->create(['reference' => 'ABSENT', 'is_active' => true]);
    $gone = Article::factory()->imported()->create(['reference' => 'GONE', 'is_active' => true]);

    ($this->import)("reference;actif\nGONE;non\n");

    expect($absent->refresh()->is_active)->toBeTrue()->and($gone->refresh()->is_active)->toBeFalse();
});

it('does not touch an article synced from Evoliz with the same reference', function () {
    $synced = Article::factory()->create(['reference' => 'ALPHA', 'unit_price_ht' => 10]);

    ($this->import)("reference;designation;prix_ht;tva\nALPHA;NEW ONE;20;5,5\n");

    expect($synced->refresh()->unit_price_ht)->toBe('10.00')
        ->and(Article::query()->whereNull('evoliz_article_id')->where('reference', 'ALPHA')->exists())->toBeTrue();
});

it('reads ; or , UTF-8 with BOM or Windows-1252, decimal comma or point, headers in any case, and warns about unknown columns', function () {
    $latin = ($this->import)(articleFixture('articles-cp1252.csv'));

    expect($latin['created'])->toBe(1)
        ->and($latin['file_warnings'])->toHaveCount(1)
        ->and($latin['file_warnings'][0])->toContain('Colonne Inconnue');
    $article = Article::query()->where('reference', 'CAFE PRE')->firstOrFail();
    expect($article->designation)->toBe('ARBRE CAFÉ PRÉCOCE')->and($article->unit_price_ht)->toBe('1234.50');

    $bom = ($this->import)(articleFixture('articles-bom.csv'));

    expect($bom)->toMatchArray(['created' => 0, 'unchanged' => 1, 'errors' => 0, 'file_warnings' => []]);
});

it('rejects the whole file when a required column is missing, or it is too large or not a CSV', function (string $contents) {
    ($this->import)($contents);
})->with([
    'no reference column' => ["designation;prix_ht\nX;1\n"],
    'too large' => [str_repeat('x', CsvReader::MAX_BYTES + 1)],
    'binary' => ["PK\x03\x04\0\0reference"],
    'empty' => [''],
])->throws(CsvRejected::class);

it('rejects a row missing what a creation needs, and the rest continue', function () {
    $report = ($this->import)("reference;designation;prix_ht;tva\nFIRST;;12;5,5\nSECOND;OK;12;5,5\n");

    expect($report)->toMatchArray(['created' => 1, 'errors' => 1])
        ->and($report['lines'][0])->toMatchArray(['line' => 2, 'key' => 'FIRST', 'action' => 'error'])
        ->and($report['lines'][0]['messages'][0])->toContain('designation')
        ->and(Article::query()->where('reference', 'FIRST')->exists())->toBeFalse()
        ->and(Article::query()->where('reference', 'SECOND')->exists())->toBeTrue();
});

it('rejects a row with an unusable value, whole', function (string $column, string $value) {
    $article = Article::factory()->imported()->create(['reference' => 'ALPHA', 'designation' => 'KEPT']);

    $report = ($this->import)("reference;designation;{$column}\nALPHA;CHANGED;{$value}\n");

    expect($report['errors'])->toBe(1)->and($article->refresh()->designation)->toBe('KEPT');
})->with([
    ['prix_ht', 'abc'],
    ['prix_ht', '-1'],
    ['prix_ht', '100000'],
    ['tva', '101'],
    ['stock', 'x'],
    ['actif', 'peut-etre'],
    ['seuil', '-2'],
]);

it('lets the first row win when a key appears twice in the file', function () {
    $report = ($this->import)("reference;designation;prix_ht;tva\nALPHA;FIRST;1;5,5\nalpha;SECOND;2;5,5\n");

    expect($report)->toMatchArray(['created' => 1, 'errors' => 1])
        ->and($report['lines'][1]['line'])->toBe(3)
        ->and(Article::query()->where('reference', 'ALPHA')->value('designation'))->toBe('FIRST')
        ->and(Article::query()->count())->toBe(1);
});

it('writes nothing on a dry run but predicts exactly what applying does', function () {
    $existing = Article::factory()->imported()->create(['reference' => 'BETA MON', 'unit_price_ht' => 1]);

    $preview = ($this->import)(articleFixture('articles.csv'), true);

    expect(Article::query()->count())->toBe(1)
        ->and($existing->refresh()->unit_price_ht)->toBe('1.00')
        ->and(ArticleAttribute::query()->count())->toBe(0)
        ->and(ActivityLog::query()->count())->toBe(0);

    expect(($this->import)(articleFixture('articles.csv')))->toEqual($preview);
});

it('warns about an unusual VAT rate but imports it', function () {
    $report = ($this->import)("reference;designation;prix_ht;tva\nALPHA;X;1;10\n");

    expect($report)->toMatchArray(['created' => 1, 'warnings' => 1])
        ->and(Article::query()->value('vat_rate'))->toBe('10.00');
});

it('lets the classifier decide when the file states no category or type', function () {
    ($this->import)(articleFixture('articles.csv'));

    $attribute = Article::query()->where('reference', 'BETA MON')->firstOrFail()->attribute;
    expect($attribute->source)->toBe(AttributeSource::Auto)
        ->and($attribute->category)->toBe('POIRIER')
        ->and($attribute->variant)->toBe('nu');
});

it('marks a stated category and type as coming from the file', function () {
    ($this->import)(articleFixture('articles.csv'));

    $attribute = Article::query()->where('reference', 'ALPHA MT POM')->firstOrFail()->attribute;
    expect($attribute->source)->toBe(AttributeSource::File)
        ->and($attribute->category)->toBe('POMMIER')
        ->and($attribute->variant)->toBe('motte')
        ->and($attribute->reorder_threshold)->toBe(5)
        ->and($attribute->needs_review)->toBeFalse();
});

it('warns about an unknown category or type and lets the classifier decide', function () {
    $report = ($this->import)("reference;designation;prix_ht;tva;essence;type\nALPHA POM;ALPHA POMMIER;1;5,5;Licorne;bizarre\n");

    expect($report)->toMatchArray(['created' => 1, 'warnings' => 2]);
    $attribute = Article::query()->firstOrFail()->attribute;
    expect($attribute->source)->toBe(AttributeSource::Auto)->and($attribute->category)->toBe('POMMIER');
});

it('never overwrites a manual category unless the file states one', function () {
    $article = Article::factory()->imported()->create(['reference' => 'ALPHA', 'designation' => 'ALPHA POMMIER']);
    ArticleAttribute::query()->create(['article_id' => $article->id, 'category' => 'FIGUIER', 'variant' => 'nu', 'source' => AttributeSource::Manual]);

    expect(($this->import)("reference;designation\nALPHA;ALPHA POMMIER\n")['unchanged'])->toBe(1);
    expect($article->attribute()->first()->category)->toBe('FIGUIER');

    ($this->import)("reference;essence\nALPHA;Poirier\n");
    $attribute = $article->attribute()->first();
    expect($attribute->category)->toBe('POIRIER')->and($attribute->source)->toBe(AttributeSource::File);
});

it('logs one activity entry with the counts, not when dry', function () {
    ($this->import)(articleFixture('articles.csv'));

    $entry = ActivityLog::query()->sole();
    expect($entry->action)->toBe(ActivityAction::Imported)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->meta_json)->toMatchArray(['kind' => 'articles', 'created' => 3, 'updated' => 0, 'unchanged' => 0, 'error' => 0]);
});
