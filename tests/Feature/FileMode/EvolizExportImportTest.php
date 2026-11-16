<?php

use App\Domain\Catalog\ArticleImport;
use App\Domain\Clients\ClientImport;
use App\Domain\Import\CsvReader;
use App\Models\Article;
use App\Models\ArticleAttribute;
use App\Models\Client;
use App\Models\User;

/* Fictional rows under the real header lines of the Evoliz list exports: BOM, every cell quoted, LF. */
beforeEach(function () {
    fileMode();
    $this->admin = User::factory()->admin()->create();
    $this->articles = fn (string $csv) => app(ArticleImport::class)->run($csv, $this->admin, false)->toArray();
    $this->clients = fn (string $csv) => app(ClientImport::class)->run($csv, $this->admin, false)->toArray();
    $this->fixture = fn (string $name) => (string) file_get_contents(base_path("tests/Fixtures/import/{$name}"));
});

it('imports the Evoliz article export as it is', function () {
    $report = ($this->articles)(($this->fixture)('evoliz-articles.csv'));

    expect($report)->toMatchArray(['created' => 4, 'updated' => 0, 'errors' => 1]);

    $alpha = Article::query()->where('reference', 'ALPHA MT POM')->firstOrFail();
    expect($alpha->designation)->toBe('IMAGINAIRE MOTTE POMMIER / PG : G41 / LOT : 0001')
        ->and($alpha->unit_price_ht)->toBe('25.00')
        ->and($alpha->vat_rate)->toBe('5.50')
        ->and($alpha->stock_qty)->toBe('12.00')
        ->and($alpha->is_stocked)->toBeTrue()
        ->and($alpha->is_active)->toBeTrue()
        ->and($alpha->supplier_name)->toBe('Fournisseur Fictif')
        ->and($alpha->sale_classification_code)->toBe('10');

    $beta = Article::query()->where('reference', 'BETA XY')->firstOrFail();
    expect($beta->unit_price_ht)->toBe('1234.50')
        ->and($beta->vat_rate)->toBe('5.50')
        ->and($beta->stock_qty)->toBe('-3.00');

    $gamma = Article::query()->where('reference', 'GAMMA RECHARGE')->firstOrFail();
    expect($gamma->is_active)->toBeFalse()->and($gamma->is_stocked)->toBeFalse();
});

it('gives the classifier the classification code, as a sync does', function () {
    ($this->articles)(($this->fixture)('evoliz-articles.csv'));

    $attribute = fn (string $reference) => ArticleAttribute::query()
        ->where('article_id', Article::query()->where('reference', $reference)->value('id'))
        ->firstOrFail();

    expect($attribute('BETA XY')->category)->toBe('POMMIER')
        ->and($attribute('GAMMA RECHARGE')->variant)->toBe('material')
        ->and($attribute('DELTA PORT')->variant)->toBe('other');
});

it('falls back on the classification label in a file without the code', function () {
    ($this->articles)("Référence;Désignation;PU HT;TVA;Classification vente\nZETA;ZETA FICTIF;10;5.5;POMMIER\n");

    expect(Article::query()->where('reference', 'ZETA')->value('sale_classification_code'))->toBe('POMMIER');
});

it('refuses a new article with no VAT rate and carries on', function () {
    $report = ($this->articles)(($this->fixture)('evoliz-articles.csv'));

    $errors = array_values(array_filter($report['lines'], fn (array $line) => $line['action'] === 'error'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['key'])->toBe('EPSILON SANS TAUX')
        ->and($errors[0]['messages'][0])->toContain('tva')
        ->and(Article::query()->where('reference', 'EPSILON SANS TAUX')->exists())->toBeFalse();
});

it('lists the unused columns of the article export in one warning, Nature among them', function () {
    $report = ($this->articles)(($this->fixture)('evoliz-articles.csv'));

    expect($report['file_warnings'])->toHaveCount(1)
        ->and($report['file_warnings'][0])->toContain('Nature', 'Quantité', 'PU TTC', 'Créateur')
        ->not->toContain('Référence', 'PU HT', 'Qté stockée', 'Classification vente');
});

it('imports the Evoliz client export as it is', function () {
    $report = ($this->clients)(($this->fixture)('evoliz-clients.csv'));

    expect($report)->toMatchArray(['created' => 3, 'updated' => 0, 'errors' => 0]);

    $client = Client::query()->where('evoliz_code', 'EXEMPLE1')->firstOrFail();
    expect($client->company_name)->toBe("SARL DE L'EXEMPLE")
        ->and($client->address_line)->toBe("1 rue de l'Exemple, Lieu-dit Fictif")
        ->and($client->postcode)->toBe('25100')
        ->and($client->town)->toBe('EXEMPLEVILLE')
        ->and($client->phone)->toBe('09.72.00.00.01')
        ->and($client->mobile)->toBe('06.00.00.00.01')
        ->and($client->is_active)->toBeTrue()
        ->and($client->contact_name)->toBeNull()
        ->and($client->email)->toBeNull()
        ->and($client->extra_field)->toBeNull();

    $inactive = Client::query()->where('evoliz_code', 'FICTIF-2')->firstOrFail();
    expect($inactive->is_active)->toBeFalse()
        ->and($inactive->address_line)->toBe('2 route Imaginaire')
        ->and($inactive->phone)->toBeNull()
        ->and($inactive->mobile)->toBeNull();

    expect(Client::query()->where('evoliz_code', 'SUISSE3')->value('address_line'))->toBe('Hameau Fictif');
});

it('warns about what looks wrong in a client export row, without refusing it', function () {
    $report = ($this->clients)(($this->fixture)('evoliz-clients.csv'));

    $line = array_values(array_filter($report['lines'], fn (array $line) => $line['key'] === 'SUISSE3'))[0];

    expect($line['action'])->toBe('created')
        ->and(implode(' ', $line['messages']))->toContain('CH-0000', 'Fax');
});

it('never imports banking columns or comments, and names them as ignored', function () {
    $report = ($this->clients)(($this->fixture)('evoliz-clients.csv'));

    $stored = json_encode(Client::query()->get()->map->getAttributes());

    expect($stored)->not->toContain('FR00FICTIF0000', 'FICTIFXX', 'BANQUE FICTIVE', 'RIB FICTIF', 'Commentaire fictif')
        ->and(json_encode($report))->not->toContain('FR00FICTIF0000', 'Commentaire fictif')
        ->and($report['file_warnings'])->toHaveCount(1)
        ->and($report['file_warnings'][0])->toContain('Banque', 'RIB', 'IBAN', 'BIC', 'Commentaires')
        ->not->toContain('Société', 'Portable', 'Désactivé');
});

it('changes nothing when the same export comes back', function (string $name, string $importer, int $rows) {
    ($this->{$importer})(($this->fixture)($name));

    expect(($this->{$importer})(($this->fixture)($name)))->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => $rows]);
})->with([
    'articles' => ['evoliz-articles.csv', 'articles', 4],
    'clients' => ['evoliz-clients.csv', 'clients', 3],
]);

it('matches export headers whatever the case, accents and spaces', function () {
    $report = ($this->clients)("\xEF\xBB\xBF\"  CODE \";\"societe / nom\";\"DESACTIVE\"\n\"H1\";\"JARDIN FICTIF\";\"oui\"\n");

    expect($report)->toMatchArray(['created' => 1, 'file_warnings' => []])
        ->and(Client::query()->where('evoliz_code', 'H1')->value('is_active'))->toBeFalse();
});

it('reads Désactivé as the opposite of actif, in every boolean spelling', function (string $cell, bool $active) {
    ($this->clients)("Code;Société / Nom;Désactivé\nB1;JARDIN FICTIF;{$cell}\n");

    expect(Client::query()->where('evoliz_code', 'B1')->value('is_active'))->toBe($active);
})->with([
    ['Oui', false], ['Non', true], ['1', false], ['0', true], ['true', false], ['FALSE', true], ['X', false],
]);

it('lets actif win over Désactivé and leaves the client as it is when neither says', function () {
    ($this->clients)("code;societe;actif;Désactivé\nW1;JARDIN FICTIF;non;Non\n");
    ($this->clients)("Code;Société / Nom;Désactivé\nW2;AUTRE JARDIN;\n");

    expect(Client::query()->where('evoliz_code', 'W1')->value('is_active'))->toBeFalse()
        ->and(Client::query()->where('evoliz_code', 'W2')->value('is_active'))->toBeTrue();
});

it('rejects a row with an unreadable Désactivé, naming the column', function () {
    $report = ($this->clients)("Code;Société / Nom;Désactivé\nE1;JARDIN FICTIF;peut-être\n");

    expect($report['errors'])->toBe(1)
        ->and($report['lines'][0]['messages'][0])->toContain('desactive');
});

it('understands every boolean spelling and French numbers', function () {
    expect(CsvReader::yesNo('X'))->toBeTrue()
        ->and(CsvReader::yesNo('True'))->toBeTrue()
        ->and(CsvReader::yesNo('false'))->toBeFalse()
        ->and(CsvReader::yesNo('peut-être'))->toBeNull()
        ->and(CsvReader::decimal("1\u{202F}234,50"))->toBe('1234.50')
        ->and(CsvReader::decimal('5,5'))->toBe('5.5');
});

it('still reads the app template, with no warning', function (string $name, string $importer) {
    expect(($this->{$importer})(($this->fixture)($name))['file_warnings'])->toBe([]);
})->with([
    'articles' => ['articles.csv', 'articles'],
    'clients' => ['clients.csv', 'clients'],
]);
