<?php

use App\Domain\Reports\StockReport;
use App\Domain\Reports\StockReportExport;
use App\Enums\AttributeSource;
use App\Enums\OrderStatus;
use App\Enums\StockAdjustmentKind;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\CategoryThreshold;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\Order;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo('2026-10-15 10:00:00');
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $article
 * @param  array<string, mixed>  $attribute
 */
function reportArticle(string $reference, ?string $category, string $type = 'nu', array $article = [], array $attribute = []): Article
{
    $model = Article::factory()->create(['reference' => $reference, 'designation' => $reference.' désignation', ...$article]);
    ArticleAttribute::query()->create([
        'article_id' => $model->id,
        'category' => $category,
        'variant' => $type,
        'needs_review' => false,
        'source' => AttributeSource::Auto,
        ...$attribute,
    ]);

    return $model;
}

function adjustment(Article $article, StockAdjustmentKind $kind, string $quantity, string $on, ?User $user = null, ?Client $client = null): void
{
    StockAdjustment::query()->create([
        'article_id' => $article->id,
        'kind' => $kind,
        'quantity' => $quantity,
        'happened_on' => $on,
        'client_id' => $client?->id,
        'user_id' => ($user ?? User::factory()->create())->id,
    ]);
}

/**
 * @param  array{tabs: list<array<string, mixed>>}  $report
 * @return array{key: string, label: string, kind: string, rows: list<array<string, mixed>>, totals: array<string, string>}
 */
function reportTab(array $report, string $key): array
{
    foreach ($report['tabs'] as $tab) {
        if ($tab['key'] === $key) {
            /** @var array{key: string, label: string, kind: string, rows: list<array<string, mixed>>, totals: array<string, string>} $tab */
            return $tab;
        }
    }

    throw new RuntimeException("No tab {$key}");
}

/**
 * @param  array{tabs: list<array<string, mixed>>}  $report
 * @return array<string, mixed>
 */
function reportRow(array $report, string $key, string $reference): array
{
    foreach (reportTab($report, $key)['rows'] as $row) {
        if ($row['reference'] === $reference) {
            return $row;
        }
    }

    throw new RuntimeException("No row {$reference} in {$key}");
}

it('computes stock to come and what to order per article', function () {
    $alpha = reportArticle('ALPHA POM', 'POMMIER', article: ['stock_qty' => 50]);
    CategoryThreshold::query()->create(['category' => 'POMMIER', 'threshold' => 40]);
    Order::factory()->status(OrderStatus::Pending)->withLines([[$alpha, 10]])->create();
    Order::factory()->status(OrderStatus::Failed)->withLines([[$alpha, 2]])->create();
    Order::factory()->status(OrderStatus::Sent)->withLines([[$alpha, 99]])->create();
    adjustment($alpha, StockAdjustmentKind::Broken, '3', '2026-03-01');
    adjustment($alpha, StockAdjustmentKind::Gift, '1.5', '2026-10-14');

    [$from, $to] = StockReport::period(null, null);
    $report = app(StockReport::class)->build($from, $to);

    expect($report['period'])->toBe(['from' => '2026-01-01', 'to' => '2026-10-15'])
        ->and(reportRow($report, 'pommier', 'ALPHA POM'))->toMatchArray([
            'stock' => '50.00',
            'reserved' => '-12.00',
            'broken' => '-3.00',
            'gifts' => '-1.50',
            'at_term' => '33.50',
            'threshold' => 40,
            'to_order' => '6.50',
            'type_label' => 'NU',
        ])
        ->and(reportTab($report, 'pommier')['totals'])->toMatchArray(['stock' => '50.00', 'at_term' => '33.50', 'to_order' => '6.50']);
});

it('takes the variety’s threshold before the category’s, and none without either', function () {
    CategoryThreshold::query()->create(['category' => 'POMMIER', 'threshold' => 40]);
    reportArticle('VARIETY POM', 'POMMIER', article: ['stock_qty' => 10], attribute: ['reorder_threshold' => 15]);
    reportArticle('CATEGORY POM', 'POMMIER', article: ['stock_qty' => 10]);
    reportArticle('NONE NOY', 'NOYER', article: ['stock_qty' => 10]);

    $report = app(StockReport::class)->build(...StockReport::period(null, null));

    expect(reportRow($report, 'pommier', 'VARIETY POM'))->toMatchArray(['threshold' => 15, 'to_order' => '5.00'])
        ->and(reportRow($report, 'pommier', 'CATEGORY POM'))->toMatchArray(['threshold' => 40, 'to_order' => '30.00'])
        ->and(reportRow($report, 'fruits_secs', 'NONE NOY'))->toMatchArray(['threshold' => null, 'to_order' => null]);
});

it('counts broken units and gifts of the period only', function () {
    $alpha = reportArticle('ALPHA POM', 'POMMIER', article: ['stock_qty' => 20]);
    $gloves = reportArticle('GANTS', null, 'material', ['stock_qty' => 100]);
    $client = Client::factory()->create(['company_name' => 'SARL DU PRE']);
    adjustment($alpha, StockAdjustmentKind::Broken, '4', '2025-12-31');
    adjustment($alpha, StockAdjustmentKind::Broken, '2', '2026-06-01');
    adjustment($alpha, StockAdjustmentKind::Gift, '1', '2026-06-02', client: $client);
    adjustment($gloves, StockAdjustmentKind::Gift, '10', '2026-06-03');
    $order = Order::factory()->status(OrderStatus::Sent)->withLines([[$alpha, 3, true], [$alpha, 5]])->create(['sent_at' => '2026-06-04 21:30:00']);
    Order::factory()->status(OrderStatus::Sent)->withLines([[$alpha, 7, true]])->create(['sent_at' => '2026-07-01 08:00:00']);

    $report = app(StockReport::class)->build(...StockReport::period('2026-06-01', '2026-06-30'));

    expect(reportRow($report, 'pommier', 'ALPHA POM'))->toMatchArray(['broken' => '-2.00', 'gifts' => '-1.00', 'at_term' => '17.00'])
        ->and(reportTab($report, StockReport::BROKEN)['rows'])->toHaveCount(1)
        ->and(reportTab($report, StockReport::BROKEN)['totals'])->toBe(['quantity' => '2.00'])
        ->and(array_map(fn (array $row) => Arr::only($row, ['date', 'source', 'order_number', 'client', 'quantity']), reportTab($report, StockReport::GIFTS)['rows']))->toBe([
            // Sent at 21:30 UTC on 4 June = 23:30 in Paris: still 4 June.
            ['date' => '2026-06-04', 'source' => 'order', 'order_number' => $order->number, 'client' => $order->client->company_name, 'quantity' => '3.00'],
            ['date' => '2026-06-02', 'source' => 'adjustment', 'order_number' => null, 'client' => 'SARL DU PRE', 'quantity' => '1.00'],
        ])
        ->and(reportTab($report, StockReport::MATERIAL_GIFTS)['totals'])->toBe(['quantity' => '10.00']);

    // A reversed period is put right.
    expect(app(StockReport::class)->build(...StockReport::period('2026-06-30', '2026-06-01'))['period'])->toBe(['from' => '2026-06-01', 'to' => '2026-06-30']);
});

it('puts articles without a sure category in À classer, and leaves out the non-stock ones', function () {
    reportArticle('MYSTERY', null);
    reportArticle('DOUBT POM', 'POMMIER', attribute: ['needs_review' => true]);
    reportArticle('FRAIS PORT', null, 'other');
    Article::factory()->create(['reference' => 'NEW ONE']);
    reportArticle('TERREAU', null, 'material');
    reportArticle('OLD POM', 'POMMIER', article: ['is_active' => false]);

    $report = app(StockReport::class)->build(...StockReport::period(null, null));
    $references = fn (string $key) => array_column(reportTab($report, $key)['rows'], 'reference');

    expect(array_column($report['tabs'], 'label'))->toBe([
        'POMMIER', 'FRUITS SECS', 'FIGUIER KAKI', 'ABRICOTIER', 'POIRIER PRUNIER CERISIER', 'AUTRES ESSENCES', 'MATERIEL', 'À CLASSER', 'PLANTS ABIMES', 'CADEAUX CLIENT', 'CADEAUX MATERIEL',
    ])
        ->and($references(StockReport::UNCLASSIFIED))->toBe(['DOUBT POM', 'MYSTERY', 'NEW ONE'])
        ->and($references('materiel'))->toBe(['TERREAU'])
        ->and($references('pommier'))->toBe([]);
});

it('shows the report to every role, on the page and in the API, without calling Evoliz', function (bool $admin) {
    Http::fake();
    reportArticle('ALPHA POM', 'POMMIER', article: ['stock_qty' => 5]);
    actingAs($admin ? User::factory()->admin()->create() : $this->user);

    get('/reports/stock?from=2026-02-01&to=2026-02-28')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Reports/Stock')
        ->where('report.period', ['from' => '2026-02-01', 'to' => '2026-02-28'])
        ->where('report.tabs.0.rows.0.reference', 'ALPHA POM'));
    get('/api/reports/stock')->assertOk()->assertJsonPath('period.from', '2026-01-01')->assertJsonPath('tabs.0.rows.0.at_term', '5.00');
    getJson('/api/reports/stock?from=15/10/2026')->assertUnprocessable();
    Http::assertNothingSent();
})->with(['sales user' => [false], 'admin' => [true]]);

it('keeps guests out', function () {
    get('/reports/stock')->assertRedirect('/login');
    get('/reports/stock.xlsx')->assertRedirect('/login');
});

it('exports one sheet per tab in the file’s order, with formulas matching the report', function () {
    CategoryThreshold::query()->create(['category' => 'POMMIER', 'threshold' => 40]);
    $alpha = reportArticle('ALPHA POM', 'POMMIER', article: ['stock_qty' => 50, 'supplier_name' => 'SEMEX']);
    reportArticle('BRAVO POM', 'POMMIER', 'motte', ['stock_qty' => 8]);
    Order::factory()->status(OrderStatus::Pending)->withLines([[$alpha, 10]])->create();
    adjustment($alpha, StockAdjustmentKind::Broken, '3', '2026-03-01');
    actingAs($this->user);

    $response = get('/reports/stock.xlsx');
    $response->assertOk()->assertDownload('stock-par-essence-2026-10-15.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    unlink($path);

    expect($book->getSheetNames())->toBe([
        'MATERIEL', 'POMMIER', 'FRUITS SECS', 'FIGUIER KAKI', 'ABRICOTIER', 'POIRIER PRUNIER CERISIER', 'AUTRES ESSENCES', 'À CLASSER', 'PLANTS ABIMES', 'CADEAUX CLIENT', 'CADEAUX MATERIEL',
    ]);

    $sheet = $book->getSheetByName('POMMIER');
    $report = app(StockReport::class)->build(...StockReport::period(null, null));
    $totals = reportTab($report, 'pommier')['totals'];

    expect($sheet->rangeToArray('A1:M1')[0])->toBe(['Référence Article', 'Désignation Article', 'NU/MOTTE', 'ESSENCE', 'Qté Stock Réel', 'Qté Réservée', 'Plants abîmés', 'Cadeaux hors cde', 'Qté à terme', 'Seuil', 'À commander', 'Fournisseur', 'Actif'])
        ->and($sheet->getCell('M2')->getValue())->toBe('Oui')
        ->and($sheet->getCell('E2')->getValue())->toBe(50)
        ->and($sheet->getCell('F2')->getValue())->toBe(-10)
        ->and($sheet->getCell('I2')->getValue())->toBe('=SUM(E2:H2)')
        ->and($sheet->getCell('L2')->getValue())->toBe('SEMEX')
        ->and($sheet->getCell('C3')->getValue())->toBe('MOTTE')
        ->and($sheet->getCell('A4')->getValue())->toBe('TOTAL')
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:M3');

    // The formulas give the same numbers as the screen.
    foreach (['E' => 'stock', 'F' => 'reserved', 'G' => 'broken', 'I' => 'at_term', 'K' => 'to_order'] as $column => $field) {
        expect((float) $sheet->getCell("{$column}4")->getCalculatedValue())->toBe((float) $totals[$field]);
    }
    $broken = $book->getSheetByName('PLANTS ABIMES');
    expect((float) $broken->getCell('D3')->getCalculatedValue())->toBe(3.0)
        ->and($broken->getCell('D2')->getValue())->toBe(3)
        ->and($broken->getCell('A2')->getFormattedValue())->toBe('01/03/2026');
});

it('writes the workbook quickly for a full catalogue', function () {
    CategoryThreshold::query()->create(['category' => 'POMMIER', 'threshold' => 10]);
    // 900 articles, about the size of the live catalogue (inserted in bulk: the factory runs out of unique words).
    $now = now();
    DB::table('articles')->insert(array_map(fn (int $index) => [
        'evoliz_article_id' => 5_000_000 + $index, 'reference' => sprintf('VAR%04d POM', $index), 'designation' => sprintf('VAR%04d POMMIER / CAL : 10-12', $index),
        'unit_price_ht' => 30, 'vat_rate' => 5.5, 'is_stocked' => true, 'stock_qty' => $index % 40, 'is_active' => true, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ], range(1, 900)));
    DB::table('article_attributes')->insertUsing(
        ['article_id', 'category', 'variant', 'needs_review', 'source', 'created_at', 'updated_at'],
        DB::table('articles')->selectRaw("id, 'POMMIER', 'nu', false, 'auto', now(), now()"),
    );
    $report = app(StockReport::class)->build(...StockReport::period(null, null));

    $started = microtime(true);
    app(StockReportExport::class)->write($report, $path = tempnam(sys_get_temp_dir(), 'xlsx'));
    $seconds = microtime(true) - $started;
    unlink($path);

    expect(reportTab($report, 'pommier')['rows'])->toHaveCount(900)
        ->and($seconds)->toBeLessThan(2.0);
});

it('applies the category threshold to active articles only, and says which are active', function () {
    CategoryThreshold::query()->create(['category' => 'POMMIER', 'threshold' => 10]);
    reportArticle('IN STOCK', 'POMMIER', article: ['stock_qty' => 3]);
    $ordered = reportArticle('ORDERED', 'POMMIER', article: ['stock_qty' => 0]);
    $rejected = reportArticle('REJECTED ONLY', 'POMMIER', article: ['stock_qty' => 0]);
    $old = reportArticle('ORDERED LONG AGO', 'POMMIER', article: ['stock_qty' => 0]);
    $broken = reportArticle('BROKEN', 'POMMIER', article: ['stock_qty' => 0]);
    reportArticle('OWN THRESHOLD', 'POMMIER', article: ['stock_qty' => -2], attribute: ['reorder_threshold' => 5]);
    reportArticle('OLD VARIETY', 'POMMIER', article: ['stock_qty' => -4]);
    $delivered = reportArticle('DELIVERED IN EVOLIZ', 'POMMIER', article: ['stock_qty' => 0]);
    $deliveredOld = reportArticle('DELIVERED LONG AGO', 'POMMIER', article: ['stock_qty' => 0]);
    $deletedBl = reportArticle('ON A DELETED BL', 'POMMIER', article: ['stock_qty' => 0]);
    // BLs typed in Evoliz count like app orders (the delivery mirror).
    foreach ([[$delivered, '2026-03-01', false], [$deliveredOld, '2025-06-01', false], [$deletedBl, '2026-03-01', true]] as $index => [$article, $date, $deleted]) {
        $bl = EvolizDelivery::query()->create([
            'evoliz_delivery_id' => 900 + $index, 'document_number' => "BL-{$index}", 'status_code' => 20, 'status' => 'invoice', 'documentdate' => $date,
            'total_ht' => 30, 'total_vat' => 1.65, 'total_ttc' => 31.65, 'total_quantity' => 1,
        ]);
        $bl->lines()->create(['position' => 0, 'article_id' => $article->id, 'reference' => $article->reference, 'designation' => $article->designation, 'quantity' => 1, 'unit_price_ht' => 30, 'vat_rate' => 5.5, 'total_ht' => 30, 'is_free' => false]);
        if ($deleted) {
            $bl->delete();
        }
    }

    Order::factory()->status(OrderStatus::Sent)->withLines([[$ordered, 1]])->create(['created_at' => '2026-02-01 10:00:00']);
    Order::factory()->status(OrderStatus::Rejected)->withLines([[$rejected, 1]])->create();
    Order::factory()->status(OrderStatus::Sent)->withLines([[$old, 1]])->create(['created_at' => '2025-10-14 10:00:00']);
    adjustment($broken, StockAdjustmentKind::Broken, '1', '2025-11-01');

    $rows = array_column(reportTab(app(StockReport::class)->build(...StockReport::period(null, null)), 'pommier')['rows'], null, 'reference');

    expect(array_map(fn (array $row) => [$row['active'], $row['threshold'], $row['to_order']], $rows))->toBe([
        'BROKEN' => [true, 10, '10.00'],            // a journal entry within 12 months (before the report period, so not subtracted)
        'DELIVERED IN EVOLIZ' => [true, 10, '10.00'],
        'DELIVERED LONG AGO' => [false, null, null],
        'IN STOCK' => [true, 10, '7.00'],
        'OLD VARIETY' => [false, null, null],
        'ON A DELETED BL' => [false, null, null],
        'ORDERED' => [true, 10, '10.00'],
        'ORDERED LONG AGO' => [false, null, null],
        'OWN THRESHOLD' => [true, 5, '7.00'],
        'REJECTED ONLY' => [false, null, null],
    ]);
});
