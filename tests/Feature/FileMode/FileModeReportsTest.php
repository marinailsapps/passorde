<?php

use App\Domain\Reports\SalesReport;
use App\Domain\Reports\SalesReportExport;
use App\Domain\Reports\StockReport;
use App\Domain\Reports\StockReportExport;
use App\Domain\Reports\WriteOffReport;
use App\Domain\Reports\WriteOffReportExport;
use App\Enums\AttributeSource;
use App\Enums\OrderStatus;
use App\Enums\WriteOffType;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    Queue::fake();
    travelTo('2026-11-05 10:00');
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->seller = User::factory()->create(['name' => 'Julie Fictive']);
    $this->variety = Article::factory()->imported()->create(['reference' => 'VISION POM', 'stock_qty' => 20, 'is_stocked' => true, 'unit_price_ht' => '32.00', 'vat_rate' => '5.50']);
    ArticleAttribute::query()->updateOrCreate(['article_id' => $this->variety->id], ['category' => 'POMMIER', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Manual]);
    $this->client = Client::factory()->imported()->create(['company_name' => 'SARL FICTIF', 'postcode' => '88200']);
});

function reportOrder(?string $exportedAt): Order
{
    $order = Order::factory()->for(test()->client)->for(test()->seller)->status(OrderStatus::Approved)
        ->withLines([[test()->variety, 3], [test()->variety, 1, true]])
        ->create(['total_quantity' => '4.00', 'total_ht' => '96.00', 'total_vat' => '5.28', 'total_ttc' => '101.28']);

    if ($exportedAt !== null) {
        $order->forceFill(['exported_at' => $exportedAt])->save();
    }

    return $order;
}

/**
 * @return array{from: string, to: string, delivered: bool, invoiced: bool, seller_id: int|null}
 */
function salesFiltersFor(string $from, string $to, ?int $seller = null): array
{
    return ['from' => $from, 'to' => $to, 'delivered' => true, 'invoiced' => true, 'seller_id' => $seller];
}

it('counts exported orders only in Ventes, by export date in Paris time', function () {
    $exported = reportOrder('2026-11-03 09:00');
    $lateEvening = reportOrder('2026-10-31 23:30'); // 1 November in Paris
    reportOrder(null);
    Order::factory()->for($this->client)->withLines([[$this->variety, 5]])->create();

    $report = app(SalesReport::class);
    $november = SalesReport::aggregate($report->input(salesFiltersFor('2026-11-01', '2026-11-30')));

    expect($november['cards'])->toBe(['count' => 2, 'quantity' => '8.00', 'free_quantity' => '2.00', 'ht' => '192.00', 'vat' => '10.56', 'ttc' => '202.56'])
        ->and(array_column($november['deliveries'], 'number'))->toBe([$exported->number, $lateEvening->number])
        ->and($november['deliveries'][0])->toMatchArray(['date' => '2026-11-03', 'client' => 'SARL FICTIF', 'invoiced' => false])
        ->and(SalesReport::aggregate($report->input(salesFiltersFor('2026-10-01', '2026-10-31')))['cards']['count'])->toBe(0)
        ->and(array_column($november['groups']['category'], 'key'))->toBe(['POMMIER'])
        ->and(array_column($november['groups']['department'], 'key'))->toBe(['88']);
});

it('filters Ventes by seller and lists only the authors of exported orders', function () {
    reportOrder('2026-11-03 09:00');
    $other = User::factory()->create(['name' => 'Autre Vendeur']);
    Order::factory()->for($this->client)->for($other)->status(OrderStatus::Approved)->withLines([[$this->variety, 1]])->create();

    $report = app(SalesReport::class);

    expect(SalesReport::sellers())->toBe([['id' => $this->seller->id, 'name' => 'Julie Fictive']])
        ->and(SalesReport::aggregate($report->input(salesFiltersFor('2026-11-01', '2026-11-30', $this->seller->id)))['cards']['count'])->toBe(1)
        ->and(SalesReport::aggregate($report->input(salesFiltersFor('2026-11-01', '2026-11-30', $other->id)))['cards']['count'])->toBe(0);
});

it('shows the exported order on the Ventes page, for every role', function () {
    $order = reportOrder('2026-11-03 09:00');

    actingAs($this->seller);
    get('/reports/sales')->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.cards.count', 1)
        ->where('report.deliveries.0.number', $order->number)
        ->where('syncedAt', null));
});

it('words the Ventes workbook for orders, not delivery notes', function () {
    reportOrder('2026-11-03 09:00');
    $input = app(SalesReport::class)->input(salesFiltersFor('2026-11-01', '2026-11-30'));
    $book = (new SalesReportExport)->spreadsheet($input, SalesReport::aggregate($input));

    $summary = $book->getSheetByName('Résumé');
    $detail = $book->getSheetByName('Détail');

    expect($summary->getCell('A6')->getValue())->toBe('Nombre de commandes')
        ->and($summary->getCell('B2')->getValue())->toBe('Commandes exportées')
        ->and($summary->getCell('B4')->getValue())->not->toContain('Evoliz')
        ->and($book->getSheetByName('Par essence')->getCell('B1')->getValue())->toBe('Nb commandes')
        ->and($detail->getCell('A1')->getValue())->toBe('N° commande')
        ->and($detail->getCell('D2')->getValue())->toBe('Exportée')
        ->and($detail->getCell('M1')->getValue())->toBe('Commande HT');
});

it('lists the free units of exported orders as gifts, by export date, in both reports', function () {
    $order = reportOrder('2026-11-03 09:00');
    reportOrder(null);

    $from = Carbon::parse('2026-11-01', 'Europe/Paris');
    $to = Carbon::parse('2026-11-30', 'Europe/Paris');

    $gifts = collect(app(StockReport::class)->build($from, $to)['tabs'])->firstWhere('key', StockReport::GIFTS);
    expect($gifts['rows'])->toHaveCount(1)
        ->and($gifts['rows'][0])->toMatchArray(['date' => '2026-11-03', 'source' => 'order', 'order_number' => $order->number, 'quantity' => '1.00']);

    $rows = app(WriteOffReport::class)->rows($from, $to, [WriteOffType::OrderGift]);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['date' => '2026-11-03', 'order_number' => $order->number, 'quantity' => '1.00'])
        ->and(app(WriteOffReport::class)->totals($from, $to, [WriteOffType::OrderGift])['entries'])->toBe(1);

    $outside = Carbon::parse('2026-12-01', 'Europe/Paris');
    expect(app(WriteOffReport::class)->rows($outside, $outside, [WriteOffType::OrderGift]))->toBe([]);
});

it('keeps the stock report\'s arithmetic through the whole cycle', function () {
    $from = Carbon::parse('2026-11-01', 'Europe/Paris');
    $to = Carbon::parse('2026-11-30', 'Europe/Paris');
    $row = function () use ($from, $to): array {
        /** @var list<array<string, mixed>> $tabs */
        $tabs = app(StockReport::class)->build($from, $to)['tabs'];
        $tab = array_values(array_filter($tabs, fn (array $tab) => $tab['key'] === 'pommier'))[0];

        return array_values(array_filter($tab['rows'], fn (array $row) => $row['reference'] === 'VISION POM'))[0];
    };

    $order = reportOrder(null);
    expect($row())->toMatchArray(['stock' => '20.00', 'reserved' => '-4.00', 'at_term' => '16.00']);

    postJson('/api/admin/orders/mark-exported', ['uuids' => [$order->uuid]])->assertOk();
    expect($row())->toMatchArray(['stock' => '16.00', 'reserved' => '0.00', 'at_term' => '16.00']);

    postJson("/api/admin/orders/{$order->uuid}/unmark-exported")->assertOk();
    expect($row())->toMatchArray(['stock' => '20.00', 'reserved' => '-4.00', 'at_term' => '16.00']);
});

it('leaves out the Evoliz column and wording in the report workbooks', function () {
    reportOrder('2026-11-03 09:00');
    $from = Carbon::parse('2026-11-01', 'Europe/Paris');
    $to = Carbon::parse('2026-11-30', 'Europe/Paris');

    $stock = (new StockReportExport)->spreadsheet(app(StockReport::class)->build($from, $to));
    $gifts = $stock->getSheetByName('CADEAUX CLIENT');
    $broken = $stock->getSheetByName('PLANTS ABIMES');
    $pommier = $stock->getSheetByName('POMMIER');

    expect($gifts->getCell('H1')->getValue())->toBeNull()
        ->and($gifts->getCell('G1')->getValue())->toBe('Remarque')
        ->and($broken->getCell('G1')->getValue())->toBeNull()
        ->and($pommier->getComment('M1')->getText()->getPlainText())->not->toContain('Evoliz')
        ->and($pommier->getComment('G1')->getText()->getPlainText())->not->toContain('Evoliz');

    $types = [WriteOffType::OrderGift];
    $writeOffs = (new WriteOffReportExport)->spreadsheet(app(WriteOffReport::class)->rows($from, $to, $types), $from, $to, $types)->getActiveSheet();

    expect($writeOffs->getCell('J1')->getValue())->toBe('Saisi par')
        ->and($writeOffs->getCell('K1')->getValue())->toBeNull();
});

it('keeps the Evoliz wording in Evoliz mode', function () {
    evolizMode();
    $from = Carbon::parse('2026-11-01', 'Europe/Paris');
    $to = Carbon::parse('2026-11-30', 'Europe/Paris');

    $stock = (new StockReportExport)->spreadsheet(app(StockReport::class)->build($from, $to));
    $types = [WriteOffType::OrderGift];
    $writeOffs = (new WriteOffReportExport)->spreadsheet([], $from, $to, $types)->getActiveSheet();
    $input = app(SalesReport::class)->input(salesFiltersFor('2026-11-01', '2026-11-30'));
    $sales = (new SalesReportExport)->spreadsheet($input, SalesReport::aggregate($input));

    expect($stock->getSheetByName('CADEAUX CLIENT')->getCell('H1')->getValue())->toBe('Stock Evoliz')
        ->and($writeOffs->getCell('K1')->getValue())->toBe('Stock Evoliz')
        ->and($sales->getSheetByName('Résumé')->getCell('A6')->getValue())->toBe('Nombre de BL')
        ->and($sales->getSheetByName('Détail')->getCell('A1')->getValue())->toBe('N° BL');
});
