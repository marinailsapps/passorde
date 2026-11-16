<?php

use App\Enums\AttributeSource;
use App\Enums\OrderStatus;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\Client;
use App\Models\EvolizDelivery;
use App\Models\EvolizUser;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', 'Europe/Paris'));
    $this->seller = User::factory()->create(['name' => 'Ysolde Dupont']);
    actingAs($this->seller);
    $this->client = Client::factory()->create(['company_name' => 'SARL FICTIF', 'postcode' => '59380']);
    $this->variety = Article::factory()->create(['reference' => 'VELOURS TIGE POM']);
    ArticleAttribute::query()->create(['article_id' => $this->variety->id, 'category' => 'POMMIER', 'variant' => 'nu', 'needs_review' => false, 'source' => AttributeSource::Auto]);
    $this->gloves = Article::factory()->create(['reference' => 'GANTS']);
    ArticleAttribute::query()->create(['article_id' => $this->gloves->id, 'category' => null, 'variant' => 'material', 'needs_review' => false, 'source' => AttributeSource::Auto]);
});

/**
 * @param  list<array{0: Article, 1: int, 2: int|float}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function salesBl(string $number, string $date, array $lines, array $attributes = []): EvolizDelivery
{
    static $id = 2000;
    $ht = 0;
    $vat = 0;
    foreach ($lines as [$article, $quantity, $price]) {
        $ht += $quantity * $price;
        $vat += $quantity * $price * ($article->reference === 'GANTS' ? 0.2 : 0.055);
    }
    $delivery = EvolizDelivery::query()->create([
        'evoliz_delivery_id' => ++$id, 'document_number' => $number, 'client_id' => test()->client->id, 'client_name' => 'SARL FICTIF', 'evoliz_user_id' => 50001,
        'status_code' => 2, 'status' => 'create', 'invoiced' => false, 'documentdate' => $date,
        'total_ht' => $ht, 'total_vat' => round($vat, 2), 'total_ttc' => round($ht + $vat, 2), 'total_quantity' => array_sum(array_column($lines, 1)),
        ...$attributes,
    ]);
    foreach ($lines as $position => [$article, $quantity, $price]) {
        $delivery->lines()->create([
            'position' => $position, 'article_id' => $article->id, 'reference' => $article->reference, 'designation' => $article->reference.' désignation',
            'quantity' => $quantity, 'unit_price_ht' => $price, 'vat_rate' => $article->reference === 'GANTS' ? 20 : 5.5, 'total_ht' => $quantity * $price, 'is_free' => $price == 0,
        ]);
    }

    return $delivery;
}

it('sums the BLs of the current month by default, never the orders still waiting', function () {
    salesBl('BL-1', '2026-10-05', [[$this->variety, 10, 32], [$this->variety, 1, 0], [$this->gloves, 1, 20]], ['status_code' => 20, 'status' => 'invoice', 'invoiced' => true]);
    salesBl('BL-2', '2026-10-20', [[$this->variety, 2, 30]]);
    salesBl('BL-SEPT', '2026-09-30', [[$this->variety, 5, 30]]);
    salesBl('BL-GONE', '2026-10-06', [[$this->variety, 5, 30]])->delete();
    Order::factory()->for($this->client)->withLines([[$this->variety, 50]])->create();

    get('/reports/sales')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Reports/Sales')
        ->where('filters', ['from' => '2026-10-01', 'to' => '2026-10-22', 'delivered' => true, 'invoiced' => true, 'seller_id' => null])
        ->where('report.cards', ['count' => 2, 'quantity' => '13.00', 'free_quantity' => '1.00', 'ht' => '400.00', 'vat' => '24.90', 'ttc' => '424.90'])
        ->where('report.groups.category.0.key', 'POMMIER')
        ->where('report.groups.category.0.ttc', '400.90')
        ->where('report.groups.category.1.key', '~material')
        ->where('report.groups.department.0.key', '59')
        ->where('report.deliveries.0.number', 'BL-2')
        ->where('presets.0.key', 'this_week'));
});

it('filters by invoiced state and by seller', function () {
    salesBl('BL-1', '2026-10-05', [[$this->variety, 10, 32]], ['status_code' => 20, 'status' => 'invoice', 'invoiced' => true]);
    salesBl('BL-2', '2026-10-20', [[$this->variety, 2, 30]]);
    EvolizUser::query()->create(['evoliz_user_id' => 50001, 'label' => 'OPERATEUR', 'user_id' => $this->seller->id]);
    $other = User::factory()->create(['name' => 'Elouan Clairval']);
    $sent = Order::factory()->for($this->client)->for($other)->status(OrderStatus::Sent)->withLines([[$this->variety, 4]])->create();
    salesBl('BL-3', '2026-10-21', [[$this->variety, 4, 30]], ['order_id' => $sent->id]);

    get('/reports/sales?delivered=0&invoiced=1')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters.delivered', false)
        ->where('report.cards.count', 1)
        ->where('report.deliveries.0.number', 'BL-1'));

    get("/reports/sales?seller={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('report.cards.count', 1)
        ->where('report.deliveries.0.number', 'BL-3')
        ->where('sellers', [['id' => $other->id, 'name' => 'Elouan Clairval'], ['id' => $this->seller->id, 'name' => 'Ysolde Dupont']]));
});

it('exports Résumé, one sheet per grouping and Détail, with numbers and formulas', function () {
    salesBl('BL-1', '2026-10-05', [[$this->variety, 10, 32], [$this->variety, 1, 0], [$this->gloves, 1, 20]], ['status_code' => 20, 'status' => 'invoice', 'invoiced' => true]);
    salesBl('BL-2', '2026-10-20', [[$this->variety, 2, 30]]);

    $response = get('/reports/sales.xlsx?from=2026-10-01&to=2026-10-31')->assertOk()->assertDownload('ventes-2026-10-01-2026-10-31.xlsx');
    file_put_contents($path = tempnam(sys_get_temp_dir(), 'xlsx'), $response->streamedContent());
    $book = IOFactory::load($path);
    unlink($path);

    expect($book->getSheetNames())->toBe(['Résumé', 'Par essence', 'Par variété', 'Par client', 'Par mois', 'Par département', 'Nu-Motte', 'Détail']);

    $summary = $book->getSheetByName('Résumé');
    expect($summary->getCell('B1')->getValue())->toBe('01/10/2026 – 31/10/2026')
        ->and($summary->getCell('B6')->getValue())->toBe(2)
        ->and($summary->getCell('B7')->getValue())->toBe(13)
        ->and($summary->getCell('B9')->getValue())->toBe("='Détail'!M6")
        ->and($summary->getCell('B9')->getCalculatedValue())->toEqual(400)
        ->and(round($summary->getCell('B11')->getCalculatedValue(), 2))->toEqual(424.9);

    $category = $book->getSheetByName('Par essence');
    expect($category->rangeToArray('A1:F3', null, false, false))->toBe([
        ['Libellé', 'Nb BL', 'Plants', 'dont offertes', 'HT', 'TTC'],
        ['POMMIER', 2, 13, 1, 380, 400.9],
        ['Matériel', 1, 0, 0, 20, 24],
    ])->and($category->getCell('E4')->getValue())->toBe('=SUM(E2:E3)')
        ->and($category->getCell('B4')->getValue())->toBeNull();

    expect($book->getSheetByName('Par mois')->getCell('A2')->getValue())->toBe('Octobre 2026')
        ->and($book->getSheetByName('Par client')->getCell('B3')->getValue())->toBe('=SUM(B2:B2)');

    $detail = $book->getSheetByName('Détail');
    expect($detail->rangeToArray('A2:O5', null, false, false))->toBe([
        ['BL-2', 46315.0, 'SARL FICTIF', 'Bon', 'Evoliz', 'VELOURS TIGE POM', 'VELOURS TIGE POM désignation', 2, 30, null, 5.5, 60, 60, 3.3, 63.3],
        ['BL-1', 46300.0, 'SARL FICTIF', 'Facturé', 'Evoliz', 'VELOURS TIGE POM', 'VELOURS TIGE POM désignation', 10, 32, null, 5.5, 320, 340, 21.6, 361.6],
        ['BL-1', 46300.0, 'SARL FICTIF', 'Facturé', 'Evoliz', 'VELOURS TIGE POM', 'VELOURS TIGE POM désignation', 1, 0, 'Oui', 5.5, 0, null, null, null],
        ['BL-1', 46300.0, 'SARL FICTIF', 'Facturé', 'Evoliz', 'GANTS', 'GANTS désignation', 1, 20, null, 20, 20, null, null, null],
    ])->and($detail->getCell('M6')->getValue())->toBe('=SUM(M2:M5)');
});

it('works from the delivered orders of the app while Evoliz is switched on but not running', function (Closure $stop) {
    // A BL made in Evoliz itself, one from an order sent before the lapse, and an order exported during it.
    salesBl('BL-1', '2026-10-20', [[$this->variety, 4, 30]]);
    $sent = Order::factory()->for($this->client)->for($this->seller)->status(OrderStatus::Sent)->withLines([[$this->variety, 2]])
        ->create(['sent_at' => Carbon::parse('2026-10-15 10:00', 'Europe/Paris'), 'evoliz_delivery_number' => 'BL-2']);
    salesBl('BL-2', '2026-10-15', [[$this->variety, 2, 30]], ['order_id' => $sent->id]);
    Order::factory()->for($this->client)->for($this->seller)->status(OrderStatus::Approved)->withLines([[$this->variety, 3]])
        ->create(['standalone_at' => now(), 'exported_at' => Carbon::parse('2026-10-21 09:00', 'Europe/Paris')]);

    get('/reports/sales?from=2026-10-01&to=2026-10-31')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('source', 'evoliz')
        ->where('report.cards.count', 2)
        ->where('report.cards.quantity', '6.00'));

    $stop();

    get('/reports/sales?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('source', 'file')
        ->where('syncedAt', null)
        ->where('report.cards.count', 2)
        ->where('report.cards.quantity', '5.00')
        ->where('sellers', [['id' => $this->seller->id, 'name' => 'Ysolde Dupont']]));

    fileMode();
    get('/reports/sales?from=2026-10-01&to=2026-10-31')->assertInertia(fn (AssertableInertia $page) => $page->where('source', 'file')->where('report.cards.count', 2));
})->with([
    'licence lapsed' => [fn () => licenceExpired()],
    'no licence key' => [fn () => licenceMissing()],
    'a key missing' => [fn () => config()->set('passorde-connect.evoliz.secret_key', null)],
]);
