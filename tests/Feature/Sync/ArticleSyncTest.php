<?php

use App\Enums\SyncKind;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Integrations\Sync\Jobs\SyncJob;
use App\Integrations\Sync\Sinks\ArticleSink;
use App\Integrations\Sync\Sinks\EvolizText;
use App\Models\Article;
use App\Models\SyncRun;

use function Pest\Laravel\artisan;

const ARTICLE_PAGES = ['articles' => ['articles-page-1', 'articles-page-2']];

function runArticleSync(): SyncRun
{
    $run = SyncRun::queue(SyncKind::Articles, SyncTrigger::Console);
    SyncJob::dispatchSync($run);

    return $run->fresh();
}

it('imports every page of articles', function () {
    fakeEvoliz(ARTICLE_PAGES);

    $run = runArticleSync();

    expect(Article::query()->count())->toBe(6)
        ->and($run->status)->toBe(SyncStatus::Succeeded)
        ->and($run->rows_seen)->toBe(6)
        ->and($run->rows_changed)->toBe(6);
});

it('maps the recorded Evoliz payload, including stock', function () {
    fakeEvoliz(ARTICLE_PAGES);

    runArticleSync();

    $above = Article::query()->where('evoliz_article_id', 7000240)->sole();
    expect($above->only(['reference', 'designation', 'unit_price_ht', 'vat_rate', 'sale_classification_id', 'is_stocked', 'stock_qty', 'is_active']))->toBe([
        'reference' => 'ASTRA MT POM',
        'designation' => 'ASTRA MOTTE POMMIER / PG : M9 / CAL : 12-14 / LOT : A26-0412',
        'unit_price_ht' => '88.00',
        'vat_rate' => '5.50',
        'sale_classification_id' => 500030,
        'is_stocked' => true,
        // 7 before a test BL took 2 + 1 units.
        'stock_qty' => '4.00',
        'is_active' => true,
    ]);

    expect(Article::query()->where('reference', 'B3Q3 POMMIER')->value('stock_qty'))->toBe('-9.00');
    // The raw HTML designation is kept for free-unit lines.
    expect($above->designation_raw)->toBe('ASTRA MOTTE POMMIER&nbsp;&nbsp;/ PG :&nbsp;M9 / CAL : 12-14 / LOT : A26-0412');
});

it('decodes HTML entities in designations', function () {
    expect(EvolizText::cleanDesignation('TOPAZE POMMIER /&nbsp;PG&nbsp;BA29 /&nbsp;CAL :&nbsp;12-14'))
        ->toBe('TOPAZE POMMIER / PG BA29 / CAL : 12-14')
        ->and(EvolizText::cleanDesignation('<b>PLANT</b> &amp; TUTEUR'))->toBe('PLANT & TUTEUR');
});

it('decodes HTML entities in references but keeps the raw designation for Evoliz', function () {
    $mapped = ArticleSink::map([
        'reference' => 'OPALE RIVA MOTT&Eacute;&nbsp;H',
        'designation' => 'OPALE RIVA MOTT&Eacute; POMMIER&nbsp;/ CAL : 8-10',
    ]);

    expect($mapped)->toMatchArray([
        'reference' => 'OPALE RIVA MOTTÉ H',
        'designation' => 'OPALE RIVA MOTTÉ POMMIER / CAL : 8-10',
        'designation_raw' => 'OPALE RIVA MOTT&Eacute; POMMIER&nbsp;/ CAL : 8-10',
    ]);
});

it('keeps a null stock for articles without stock management', function () {
    expect(ArticleSink::map(['reference' => 'TABLIER', 'stock_management' => false, 'stocked_quantity' => null]))
        ->toMatchArray(['is_stocked' => false, 'stock_qty' => null]);
});

it('counts no changes when nothing changed', function () {
    fakeEvoliz(ARTICLE_PAGES);
    runArticleSync();

    expect(runArticleSync()->rows_changed)->toBe(0);
});

it('moves updated_at only for articles that changed, so phones fetch small deltas', function () {
    fakeEvoliz(ARTICLE_PAGES);
    Pest\Laravel\travelTo('2026-10-01 08:00:00');
    runArticleSync();
    Article::query()->where('evoliz_article_id', 7000240)->update(['stock_qty' => 99]);

    Pest\Laravel\travelTo('2026-10-01 09:00:00');
    runArticleSync();

    expect(Article::query()->where('updated_at', '>', '2026-10-01 08:30:00')->pluck('evoliz_article_id')->all())->toBe([7000240])
        ->and(Article::query()->where('synced_at', '<', '2026-10-01 09:00:00')->count())->toBe(0);
});

it('flags articles missing from Evoliz as inactive', function () {
    $gone = Article::factory()->create();
    fakeEvoliz(ARTICLE_PAGES);

    runArticleSync();

    expect($gone->fresh()->is_active)->toBeFalse();
});

it('runs from the console command', function () {
    fakeEvoliz(ARTICLE_PAGES);

    artisan('evoliz:sync-articles', ['--trigger' => 'schedule'])->assertSuccessful();

    expect(SyncRun::query()->sole()->trigger)->toBe(SyncTrigger::Schedule);
});
