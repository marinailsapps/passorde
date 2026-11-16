<?php

use App\Domain\Orders\OrderExport;
use App\Enums\OrderStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

const EXPORT_HEADER = ['N° commande', 'Date commande', 'Date validation', 'Vendeur', 'Code client', 'Client à créer', 'Société', 'Contact', 'Adresse', 'Code postal', 'Ville', 'Téléphone', 'Mobile', 'Email', 'N° adhérent', 'Mode de paiement', 'Commentaire commande', 'Ligne', 'Référence', 'Désignation', 'Remarque ligne', 'Offerte', 'Quantité', 'PU HT', 'TVA %', 'Total ligne HT', 'Total commande HT', 'TVA', 'TTC'];

beforeEach(function () {
    fileMode();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create(['name' => 'Opératrice']);
    actingAs($this->admin);
    $this->seller = User::factory()->create(['name' => 'Julie Fictive']);
    $this->variety = Article::factory()->imported()->create(['reference' => 'VISION POM', 'designation' => 'VELOURS TIGE', 'unit_price_ht' => '32.00', 'vat_rate' => '5.50']);
    $this->client = Client::factory()->imported()->create([
        'evoliz_code' => 'C00042', 'company_name' => 'SARL FICTIF', 'contact_name' => 'Elouan Exemple', 'address_line' => '2 place Exemple',
        'postcode' => '26200', 'town' => 'LAMBERTOU', 'phone' => '09 70 00 00 00', 'mobile' => '06 00 00 00 00', 'email' => 'sarl@example.test', 'extra_field' => 'NP-58321',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function exportable(array $attributes = []): Order
{
    $order = Order::factory()->for(test()->client)->for(test()->seller)->status(OrderStatus::Approved)
        ->withLines([[test()->variety, 3], [test()->variety, 1, true]])
        ->create(['total_quantity' => '4.00', 'total_ht' => '96.00', 'total_vat' => '5.28', 'total_ttc' => '101.28', ...$attributes]);
    $order->forceFill(['approved_at' => '2026-11-03 09:30:00', 'created_at' => '2026-11-02 23:30:00'])->save();

    return $order;
}

/**
 * @return list<list<string>>
 */
function csvRows(string $content): array
{
    $file = fopen('php://memory', 'r+');
    fwrite($file, substr($content, 3));
    rewind($file);
    $rows = [];
    while (($row = fgetcsv($file, null, ';', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

it('writes a CSV for French Excel: BOM, semicolons, decimal comma, one row per order line', function () {
    $paytype = Paytype::query()->create(['label' => 'Virement']);
    $order = exportable(['comment' => 'Livrer lundi', 'paytype_id' => $paytype->id]);

    $response = get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertOk();
    $content = $response->streamedContent();

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('.csv');

    $rows = csvRows($content);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(EXPORT_HEADER)
        ->and(array_combine(EXPORT_HEADER, $rows[1]))->toBe([
            'N° commande' => $order->number,
            'Date commande' => '03/11/2026 00:30',
            'Date validation' => '03/11/2026 10:30',
            'Vendeur' => 'Julie Fictive',
            'Code client' => 'C00042',
            'Client à créer' => 'non',
            'Société' => 'SARL FICTIF',
            'Contact' => 'Elouan Exemple',
            'Adresse' => '2 place Exemple',
            'Code postal' => '26200',
            'Ville' => 'LAMBERTOU',
            'Téléphone' => '09 70 00 00 00',
            'Mobile' => '06 00 00 00 00',
            'Email' => 'sarl@example.test',
            'N° adhérent' => 'NP-58321',
            'Mode de paiement' => 'Virement',
            'Commentaire commande' => 'Livrer lundi',
            'Ligne' => '1',
            'Référence' => 'VISION POM',
            'Désignation' => 'VELOURS TIGE',
            'Remarque ligne' => '',
            'Offerte' => 'non',
            'Quantité' => '3,00',
            'PU HT' => '32,00',
            'TVA %' => '5,50',
            'Total ligne HT' => '96,00',
            'Total commande HT' => '96,00',
            'TVA' => '5,28',
            'TTC' => '101,28',
        ])
        ->and(array_combine(EXPORT_HEADER, $rows[2]))->toMatchArray(['Ligne' => '2', 'Offerte' => 'oui', 'Quantité' => '1,00', 'PU HT' => '0,00', 'Total ligne HT' => '0,00', 'TTC' => '101,28']);
});

it('tells the operator to create a client that has no code', function () {
    $typed = Client::factory()->fromApp()->create(['company_name' => 'EURL TYPEE']);
    $order = Order::factory()->for($typed)->status(OrderStatus::Approved)->withLines([[$this->variety, 1]])->create();

    $rows = csvRows(get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertOk()->streamedContent());

    expect(array_combine(EXPORT_HEADER, $rows[1]))->toMatchArray(['Code client' => '', 'Client à créer' => 'oui', 'Société' => 'EURL TYPEE']);
});

it('keeps a text cell from running as a formula in the CSV', function (string $text, string $expected) {
    $order = exportable(['comment' => $text]);

    $rows = csvRows(get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertOk()->streamedContent());

    expect(array_combine(EXPORT_HEADER, $rows[1])['Commentaire commande'])->toBe($expected);
})->with([
    ['=HYPERLINK("http://example.test")', '\'=HYPERLINK("http://example.test")'],
    ['+1+1', "'+1+1"],
    ['-2+3', "'-2+3"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ['  =1+1', "'  =1+1"],
    ['Livrer = lundi', 'Livrer = lundi'],
    ['+33 6 00 00 00 00', '+33 6 00 00 00 00'],
]);

it('protects every text column in the CSV, not only the comment', function () {
    $this->client->update(['company_name' => '=EVIL()', 'town' => '@EVIL', 'contact_name' => '-EVIL']);
    $order = exportable();

    $row = array_combine(EXPORT_HEADER, csvRows(get('/admin/orders/export.csv?uuids[]='.$order->uuid)->streamedContent())[1]);

    expect($row)->toMatchArray(['Société' => "'=EVIL()", 'Ville' => "'@EVIL", 'Contact' => "'-EVIL"]);
});

it('writes an Excel file with numbers as numbers, frozen header, filters, and text never a formula', function () {
    $this->client->update(['company_name' => '=EVIL()']);
    $order = exportable(['comment' => '+1+1']);

    $response = get('/admin/orders/export.xlsx?uuids[]='.$order->uuid)->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();
    unlink($path);

    expect($sheet->rangeToArray('A1:AC1')[0])->toBe(EXPORT_HEADER)
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:AC3')
        ->and($sheet->getCell('A2')->getValue())->toBe($order->number)
        ->and($sheet->getCell('G2')->getValue())->toBe('=EVIL()')
        ->and($sheet->getCell('G2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('Q2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('W2')->getValue())->toBe(3)
        ->and($sheet->getCell('Y2')->getValue())->toBe(5.5)
        ->and($sheet->getCell('Z2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('V3')->getValue())->toBe('oui')
        ->and($sheet->getCell('B2')->getFormattedValue())->toBe('03/11/2026 00:30');
});

it('uses the list filters when no uuid is given, to export by default', function () {
    $waiting = exportable();
    $otherSeller = Order::factory()->status(OrderStatus::Approved)->for($this->client)->withLines([[$this->variety, 1]])->create();
    $done = exportable();
    $done->forceFill(['exported_at' => now()])->save();
    Order::factory()->for($this->client)->withLines([[$this->variety, 1]])->create();

    $numbers = fn (string $url) => array_values(array_unique(array_column(array_slice(csvRows(get($url)->assertOk()->streamedContent()), 1), 0)));

    expect($numbers('/admin/orders/export.csv'))->toBe([$waiting->number, $otherSeller->number])
        ->and($numbers('/admin/orders/export.csv?user_id='.$this->seller->id))->toBe([$waiting->number])
        ->and($numbers('/admin/orders/export.csv?status=exported'))->toBe([$done->number]);
});

it('takes approved orders only, and answers 404 when there is nothing to export', function () {
    $pending = Order::factory()->for($this->client)->withLines([[$this->variety, 1]])->create();
    $approved = exportable();

    $rows = csvRows(get("/admin/orders/export.csv?uuids[]={$pending->uuid}&uuids[]={$approved->uuid}")->assertOk()->streamedContent());
    expect(array_values(array_unique(array_column(array_slice($rows, 1), 0))))->toBe([$approved->number]);

    get('/admin/orders/export.csv?uuids[]='.$pending->uuid)->assertNotFound();
    get('/admin/orders/export.xlsx?uuids[]='.$pending->uuid)->assertNotFound();
});

it('changes nothing: taking the file marks no order', function () {
    $order = exportable();

    get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertOk()->streamedContent();

    expect($order->refresh()->exported_at)->toBeNull();
});

it('is for admins, and refused in Evoliz mode', function () {
    $order = exportable();

    actingAs($this->seller);
    get('/admin/orders/export.csv')->assertForbidden();
    get('/admin/orders/export.xlsx')->assertForbidden();

    actingAs($this->admin);
    evolizMode();
    get('/admin/orders/export.csv?uuids[]='.$order->uuid)->assertForbidden();
    get('/admin/orders/export.xlsx?uuids[]='.$order->uuid)->assertForbidden();
});

it('puts at most OrderExport::MAX_ORDERS orders in a file and in one mark, and tells the page', function () {
    $uuids = array_map(fn () => (string) Str::uuid(), range(0, OrderExport::MAX_ORDERS));

    expect(OrderExport::MAX_ORDERS)->toBe(150);
    get('/admin/orders/export.csv?'.http_build_query(['uuids' => $uuids]))->assertSessionHasErrors('uuids');
    Pest\Laravel\postJson('/api/admin/orders/mark-exported', ['uuids' => $uuids])->assertUnprocessable()->assertJsonValidationErrors('uuids');
    get('/admin/orders', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
        ->assertOk()->assertJsonPath('props.exportLimit', 150);
});
