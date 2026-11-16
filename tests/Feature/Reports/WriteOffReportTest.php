<?php

use App\Enums\EvolizStockStatus;
use App\Enums\OrderStatus;
use App\Enums\StockAdjustmentKind;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo('2026-10-21 10:00:00');
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function writeOff(Article $article, StockAdjustmentKind $kind, string $quantity, string $on, array $attributes = [], ?string $recordedAt = null): StockAdjustment
{
    $entry = StockAdjustment::query()->create([
        'article_id' => $article->id,
        'kind' => $kind,
        'quantity' => $quantity,
        'happened_on' => $on,
        'user_id' => $attributes['user_id'] ?? User::factory()->create()->id,
        ...$attributes,
    ]);
    $entry->forceFill(['created_at' => $recordedAt ?? "{$on} 09:00:00"])->saveQuietly();

    return $entry;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function writeOffProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

/**
 * The fixture of most tests, in the fourth quarter of 2026 unless said otherwise.
 *
 * @return array{alpha: Article, bravo: Article, paul: User, ysolde: User, client: Client, order: Order}
 */
function writeOffFixture(): array
{
    $alpha = Article::factory()->create(['reference' => 'ALPHA POM', 'designation' => 'ALPHA POMMIER']);
    $bravo = Article::factory()->create(['reference' => 'BRAVO MON', 'designation' => 'BRAVO POIRIERS']);
    $paul = User::factory()->admin()->create(['name' => 'Paul Opérateur']);
    $ysolde = User::factory()->create(['name' => 'Ysolde Vendeuse']);
    $client = Client::factory()->create(['company_name' => 'SARL DU PRÉ']);

    writeOff($alpha, StockAdjustmentKind::Broken, '3', '2026-10-20', ['user_id' => $paul->id, 'note' => 'Tuteur cassé', 'evoliz_status' => EvolizStockStatus::Sent, 'evoliz_applied' => true], '2026-10-20 13:45:00');
    writeOff($bravo, StockAdjustmentKind::Gift, '1', '2026-10-05', ['user_id' => $paul->id, 'client_id' => $client->id, 'evoliz_status' => EvolizStockStatus::Failed]);
    // Sent at 00:30 in Paris on 1 October: in the fourth quarter.
    $order = Order::factory()->status(OrderStatus::Sent)->withLines([[$alpha, 10], [$bravo, 2, true]])
        ->create(['user_id' => $ysolde->id, 'client_id' => $client->id, 'sent_at' => '2026-09-30 22:30:00', 'created_at' => '2026-09-29 08:15:00']);

    // Left out: a deleted entry, a gift of an order not sent yet, entries outside the period.
    writeOff($alpha, StockAdjustmentKind::Broken, '7', '2026-10-10')->delete();
    Order::factory()->status(OrderStatus::Approved)->withLines([[$bravo, 4, true]])->create();
    Order::factory()->status(OrderStatus::Sent)->withLines([[$bravo, 5, true]])->create(['sent_at' => '2026-09-30 21:30:00', 'created_at' => '2026-09-30 07:00:00']);
    writeOff($bravo, StockAdjustmentKind::Broken, '9', '2026-09-30');

    return compact('alpha', 'bravo', 'paul', 'ysolde', 'client', 'order');
}

it('lists broken units, gifts in orders and gifts outside orders of the period, newest first', function () {
    ['order' => $order, 'ysolde' => $ysolde] = writeOffFixture();
    Http::fake();
    actingAs($this->user);

    $props = writeOffProps(get('/reports/write-offs?from=2026-10-01&to=2026-10-21'));

    expect($props['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-21'])
        ->and($props['types'])->toBe(['broken', 'order_gift', 'gift'])
        ->and(array_column($props['entries']['data'], 'type'))->toBe(['broken', 'gift', 'order_gift'])
        ->and($props['entries']['data'][0])->toMatchArray([
            'date' => '2026-10-20',
            'recorded_at' => '2026-10-20T13:45:00+00:00',
            'quantity' => '3.00',
            'reference' => 'ALPHA POM',
            'designation' => 'ALPHA POMMIER',
            'client' => null,
            'order_number' => null,
            'note' => 'Tuteur cassé',
            'user' => 'Paul Opérateur',
            'evoliz_status' => 'sent',
        ])
        ->and($props['entries']['data'][1])->toMatchArray(['client' => 'SARL DU PRÉ', 'quantity' => '1.00', 'evoliz_status' => 'failed'])
        ->and($props['entries']['data'][2])->toMatchArray([
            'date' => '2026-10-01',
            'recorded_at' => '2026-09-29T08:15:00+00:00',
            'quantity' => '2.00',
            'reference' => 'BRAVO MON',
            'client' => 'SARL DU PRÉ',
            'order_number' => $order->number,
            'order_uuid' => $order->uuid,
            'note' => null,
            'user' => 'Ysolde Vendeuse',
            'user_id' => $ysolde->id,
            'evoliz_status' => null,
        ])
        ->and($props['totals'])->toBe([
            'entries' => 3,
            'quantity' => '6.00',
            'by_type' => [
                'broken' => ['entries' => 1, 'quantity' => '3.00'],
                'order_gift' => ['entries' => 1, 'quantity' => '2.00'],
                'gift' => ['entries' => 1, 'quantity' => '1.00'],
            ],
        ]);
    Http::assertNothingSent();
});

it('filters by type', function (array $types, array $expected, array $selected, string $quantity) {
    writeOffFixture();
    actingAs($this->user);

    $props = writeOffProps(get('/reports/write-offs?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-21', 'types' => $types])));

    expect(array_column($props['entries']['data'], 'type'))->toBe($expected)
        ->and($props['types'])->toBe($selected)
        ->and(array_keys($props['totals']['by_type']))->toBe($selected)
        ->and($props['totals']['quantity'])->toBe($quantity);
})->with([
    'broken units' => [['broken'], ['broken'], ['broken'], '3.00'],
    'gifts in orders' => [['order_gift'], ['order_gift'], ['order_gift'], '2.00'],
    // Rows newest first; the selected types in WriteOffType order.
    'both kinds of gifts' => [['gift', 'order_gift'], ['gift', 'order_gift'], ['order_gift', 'gift'], '3.00'],
    'gifts outside orders and broken units' => [['gift', 'broken'], ['broken', 'gift'], ['broken', 'gift'], '4.00'],
]);

it('refuses an unknown type or a malformed date', function () {
    actingAs($this->user);

    get('/reports/write-offs?types[]=sold')->assertSessionHasErrors('types.0');
    get('/reports/write-offs?from=21/10/2026')->assertSessionHasErrors('from');
    get('/reports/write-offs.xlsx?types=broken')->assertSessionHasErrors('types');
});

it('offers this year, last year, this quarter and the previous quarter', function (string $today, array $expected) {
    travelTo($today);
    actingAs($this->user);

    $props = writeOffProps(get('/reports/write-offs'));

    expect(array_map(fn (array $preset) => [$preset['key'], $preset['from'], $preset['to']], $props['presets']))->toBe($expected)
        // Without a period: this year.
        ->and($props['period'])->toBe(['from' => $expected[0][1], 'to' => $expected[0][2]]);
})->with([
    'October' => ['2026-10-21 10:00:00', [
        ['this_year', '2026-01-01', '2026-10-21'],
        ['last_year', '2025-01-01', '2025-12-31'],
        ['this_quarter', '2026-10-01', '2026-10-21'],
        ['last_quarter', '2026-07-01', '2026-09-30'],
    ]],
    'February: the previous quarter is last year’s' => ['2026-02-10 10:00:00', [
        ['this_year', '2026-01-01', '2026-02-10'],
        ['last_year', '2025-01-01', '2025-12-31'],
        ['this_quarter', '2026-01-01', '2026-02-10'],
        ['last_quarter', '2025-10-01', '2025-12-31'],
    ]],
    // 23:30 UTC on 31 March is already 1 April in Paris.
    'just after midnight in Paris' => ['2026-03-31 23:30:00', [
        ['this_year', '2026-01-01', '2026-04-01'],
        ['last_year', '2025-01-01', '2025-12-31'],
        ['this_quarter', '2026-04-01', '2026-04-01'],
        ['last_quarter', '2026-01-01', '2026-03-31'],
    ]],
]);

it('reports the period of a preset', function () {
    writeOffFixture();
    actingAs($this->user);
    /** @var list<array{key: string, from: string, to: string}> $presets */
    $presets = writeOffProps(get('/reports/write-offs'))['presets'];
    $previousQuarter = collect($presets)->firstWhere('key', 'last_quarter');

    $props = writeOffProps(get('/reports/write-offs?'.http_build_query(['from' => $previousQuarter['from'], 'to' => $previousQuarter['to']])));

    // The Paris date decides: sent at 23:30 in Paris on 30 September, broken on 30 September.
    expect(array_column($props['entries']['data'], 'quantity'))->toBe(['9.00', '5.00'])
        ->and(array_column($props['entries']['data'], 'type'))->toBe(['broken', 'order_gift']);
});

it('pages by 20, keeping the filters in the page links', function () {
    $article = Article::factory()->create();
    $author = User::factory()->create();
    foreach (range(1, 45) as $index) {
        writeOff($article, StockAdjustmentKind::Broken, (string) $index, '2026-10-0'.(1 + $index % 9), ['user_id' => $author->id]);
    }
    actingAs($this->user);
    $query = 'from=2026-10-01&to=2026-10-21&types%5B0%5D=broken';

    $first = writeOffProps(get("/reports/write-offs?{$query}"))['entries'];
    $last = writeOffProps(get("/reports/write-offs?{$query}&page=3"))['entries'];
    $keys = collect(range(1, 3))->flatMap(fn (int $page) => array_column(writeOffProps(get("/reports/write-offs?{$query}&page={$page}"))['entries']['data'], 'key'));

    expect($first['data'])->toHaveCount(20)
        ->and($first['total'])->toBe(45)
        ->and($first['last_page'])->toBe(3)
        ->and($first['next_page_url'])->toContain('from=2026-10-01')->toContain('types%5B0%5D=broken')->toContain('page=2')
        ->and($last['data'])->toHaveCount(5)
        ->and($keys->unique())->toHaveCount(45)
        ->and(array_column($first['data'], 'date'))->toBe(collect(array_column($first['data'], 'date'))->sortDesc()->values()->all());
    // The totals are those of every page.
    expect(writeOffProps(get("/reports/write-offs?{$query}"))['totals']['quantity'])->toBe(number_format(array_sum(range(1, 45)), 2, '.', ''));
});

it('opens the report for every role and keeps guests out', function (bool $admin) {
    actingAs($admin ? User::factory()->admin()->create() : $this->user);

    get('/reports/write-offs')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/WriteOffs'));
    get('/reports/write-offs.xlsx')->assertOk();
})->with(['sales user' => [false], 'admin' => [true]]);

it('sends guests to the login page', function () {
    get('/reports/write-offs')->assertRedirect('/login');
    get('/reports/write-offs.xlsx')->assertRedirect('/login');
});

/**
 * @param  TestResponse<Response>  $response
 */
function writeOffBook(TestResponse $response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    unlink($path);

    return $book;
}

it('exports the period and the types on screen as a formatted Excel sheet', function () {
    ['order' => $order] = writeOffFixture();
    actingAs($this->user);

    $response = get('/reports/write-offs.xlsx?from=2026-10-01&to=2026-10-21');
    $response->assertOk()->assertDownload('plants-abimes-cadeaux-2026-10-01-2026-10-21.xlsx');
    $book = writeOffBook($response);
    $sheet = $book->getActiveSheet();

    expect($book->getSheetNames())->toBe(['Plants abîmés et cadeaux'])
        ->and($sheet->rangeToArray('A1:K1')[0])->toBe(['Date', 'Saisi le', 'Type', 'Référence Article', 'Désignation Article', 'QTE', 'Client', 'N° commande', 'Remarque', 'Saisi par', 'Stock Evoliz'])
        ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getStyle('K1')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:K4')
        ->and($sheet->getColumnDimension('E')->getWidth())->toBe(50.0)
        ->and($sheet->getHeaderFooter()->getOddHeader())->toContain('01/10/2026 – 21/10/2026');

    // Same rows and order as the screen; dates, times and quantities are real Excel values.
    expect($sheet->rangeToArray('C2:K4', null, false, false))->toBe([
        ['Plants abîmés', 'ALPHA POM', 'ALPHA POMMIER', 3, null, null, 'Tuteur cassé', 'Paul Opérateur', 'Déduit'],
        ['Cadeau hors commande', 'BRAVO MON', 'BRAVO POIRIERS', 1, 'SARL DU PRÉ', null, null, 'Paul Opérateur', 'Échec'],
        ['Cadeau dans une commande', 'BRAVO MON', 'BRAVO POIRIERS', 2, 'SARL DU PRÉ', $order->number, null, 'Ysolde Vendeuse', 'Par le BL'],
    ])
        ->and($sheet->getCell('A2')->getValue())->toBe(ExcelDate::stringToExcel('2026-10-20'))
        ->and($sheet->getCell('A2')->getFormattedValue())->toBe('20/10/2026')
        ->and($sheet->getCell('A4')->getFormattedValue())->toBe('01/10/2026')
        // 13:45 UTC is 15:45 in Paris.
        ->and($sheet->getCell('B2')->getFormattedValue())->toBe('20/10/2026 15:45')
        ->and(is_float($sheet->getCell('B2')->getValue()))->toBeTrue()
        ->and($sheet->getCell('A5')->getValue())->toBe('TOTAL')
        ->and($sheet->getStyle('A5')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getCell('F5')->getValue())->toBe('=SUM(F2:F4)')
        ->and((float) $sheet->getCell('F5')->getCalculatedValue())->toBe(6.0);

    $broken = writeOffBook(get('/reports/write-offs.xlsx?from=2026-10-01&to=2026-10-21&types[]=broken'))->getActiveSheet();
    expect($broken->getCell('C2')->getValue())->toBe('Plants abîmés')
        ->and($broken->getCell('A3')->getValue())->toBe('TOTAL')
        ->and($broken->getHeaderFooter()->getOddHeader())->toContain('Plants abîmés');
});

it('exports an empty period with its header and a zero total', function () {
    actingAs($this->user);

    $sheet = writeOffBook(get('/reports/write-offs.xlsx?from=2020-01-01&to=2020-12-31'))->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Date')
        ->and($sheet->getCell('A2')->getValue())->toBe('TOTAL')
        ->and($sheet->getCell('F2')->getValue())->toBe(0);
});
