<?php

use App\Domain\Reports\StockReport;
use App\Domain\Reports\StockReportExport;
use App\Domain\Reports\WriteOffReportExport;
use App\Enums\WriteOffType;
use App\Models\User;
use App\Support\Profile\Profile;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
| the stock and write-off reports take their
| titles, tab names, labels and file names from the profile (reports.*, the
| texts); the rest of the suite runs on the pinned fixture, which keeps today's.
*/

function withReportsProfile(?string $name): void
{
    config()->set('passorde', Profile::load($name === null ? null : base_path("tests/Fixtures/profiles/{$name}")));
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');
    app()->setLocale('fr');
}

it('names the stock tabs, columns and workbook from the profile', function (?string $profile, array $tabs, string $title, array $columns) {
    withReportsProfile($profile);

    $report = app(StockReport::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-11-14'));
    $book = app(StockReportExport::class)->spreadsheet($report);
    $sheet = $book->getSheetByName($tabs[0]) ?? $book->getSheet(0);

    expect(array_slice(array_column($report['tabs'], 'label'), -count($tabs)))->toBe($tabs)
        ->and($book->getProperties()->getTitle())->toBe($title)
        ->and([$sheet->getCell('C1')->getValue(), $sheet->getCell('D1')->getValue(), $sheet->getCell('G1')->getValue()])->toBe($columns);
})->with([
    // No categories: one list of articles, and no gift tab for variants with their own tab (there are none).
    'neutral' => [null, ['ARTICLES', 'CASSE', 'CADEAUX'], 'Stock par catégorie', ['TYPE', 'CATÉGORIE', 'Casse']],
    'fictional' => ['fictional', ['À CLASSER', 'PERTES', 'CADEAUX', 'CADEAUX OUTILS'], 'Stock par essence', ['TYPE', 'CATÉGORIE', 'Pertes']],
]);

it('labels the write-offs and names the files from the profile', function () {
    withReportsProfile('fictional');
    actingAs(User::factory()->admin()->create());

    expect(WriteOffType::Broken->label())->toBe('Pertes')
        ->and(WriteOffReportExport::title())->toBe('Pertes et cadeaux');
    get('/reports/write-offs.xlsx?from=2026-01-01&to=2026-01-31')->assertOk()
        ->assertDownload('casse-et-cadeaux-2026-01-01-2026-01-31.xlsx');

    withReportsProfile(null);
    get('/reports/stock.xlsx')->assertOk()->assertDownload('stock-par-categorie-'.now('Europe/Paris')->toDateString().'.xlsx');
});

it('takes each report title from one setting: page, workbook, sheet and print header', function () {
    withReportsProfile('fictional');
    $stock = app(StockReportExport::class)->spreadsheet(app(StockReport::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2026-11-14')));
    $writeOffs = app(WriteOffReportExport::class)->spreadsheet([], Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'), WriteOffType::cases());

    expect(__('Stock by category'))->toBe('Stock par essence')
        ->and($stock->getProperties()->getTitle())->toBe('Stock par essence')
        ->and(__('Broken entries and gifts'))->toBe('Pertes et cadeaux')
        ->and($writeOffs->getProperties()->getTitle())->toBe('Pertes et cadeaux')
        ->and($writeOffs->getActiveSheet()->getTitle())->toBe('Pertes et cadeaux')
        ->and($writeOffs->getActiveSheet()->getHeaderFooter()->getOddHeader())->toStartWith('&L&BPertes et cadeaux&B');
});
