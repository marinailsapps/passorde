<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\User;
use App\Support\Profile\Profile;
use App\Support\Profile\Wording;
use Database\Seeders\PaytypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\seed;

/*
| A fresh installation on the truly neutral defaults (no profile at all, not
| the suite's pinned fixture) imports its articles, stock and clients from CSV,
| takes an order, approves and exports it, and opens the reports; nothing the
| pages and files show uses the words an installation's profile brings
| (installationWords() in tests/Pest.php, from the fictional test profiles).
*/

/** The installation as it starts: no profile, French. */
function neutralInstall(): void
{
    config()->set('passorde', Profile::load(null));
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');
    app()->setLocale('fr');
    fileMode();
}

/** Every text of an xlsx file: sheet names, cells, comments, page headers. */
function workbookText(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'neutral').'.xlsx';
    file_put_contents($file, $contents);
    $text = [];
    foreach (IOFactory::load($file)->getAllSheets() as $sheet) {
        $text[] = $sheet->getTitle().' '.$sheet->getHeaderFooter()->getOddHeader();
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $text[] = (string) $sheet->getCell($coordinate)->getValue();
        }
        foreach ($sheet->getComments() as $comment) {
            $text[] = $comment->getText()->getPlainText();
        }
    }
    unlink($file);

    return implode("\n", $text);
}

/**
 * The texts of props: their values, and their keys split into words (`total_quantity` reads "total quantity",
 * `clientExtraField` "client Extra Field"). A neutral installation has no older field names (aliases) to leave out.
 */
function propTexts(mixed $props): string
{
    $texts = [];
    $walk = function (mixed $value, string|int|null $key = null) use (&$walk, &$texts) {
        if (is_string($key)) {
            $texts[] = (string) preg_replace(['/_/', '/(?<=[a-z])(?=[A-Z])/'], ' ', $key);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $walk($v, $k);
            }
        } elseif (is_string($value)) {
            $texts[] = $value;
        }
    };
    $walk($props);

    return implode("\n", $texts);
}

function expectNoTradeWord(string $where, string $text): void
{
    expect(installationWordIn($text))->toBeNull("An installation word in {$where}");
}

it('runs from CSV to order, export and reports on the neutral defaults, with no installation word anywhere', function (array $categories, array $csvCategories, array $stockTabs, array $salesGroups) {
    neutralInstall();
    if ($categories !== []) {
        // The developer defined two categories in the settings file (one group each) and loads them with the articles.
        config()->set('passorde.catalog.categories', $categories);
        config()->set('passorde.catalog.category_groups', array_map(fn (array $c) => ['key' => $c['group'], 'label' => mb_strtoupper($c['label'])], $categories));
    }
    seed(PaytypeSeeder::class);
    $admin = User::factory()->admin()->create();
    $seller = User::factory()->create();
    $csv = fn (string $contents) => UploadedFile::fake()->createWithContent('import.csv', $contents);
    $inertia = fn (string $page) => get($page, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->assertOk();

    // Every French text a page or file may show (lang/fr.json with the neutral words).
    foreach (Wording::apply(json_decode((string) file_get_contents(lang_path('fr.json')), true)) as $key => $text) {
        expectNoTradeWord("lang/fr.json \"{$key}\"", "{$key} {$text}");
    }

    actingAs($admin);
    foreach (['articles', 'clients'] as $kind) {
        expectNoTradeWord("the {$kind} template", get("/admin/import/templates/{$kind}.csv")->assertOk()->streamedContent());
    }
    $articles = post('/admin/import/articles', ['file' => $csv("reference;designation;prix_ht;tva;stock;gestion_stock;categorie\nCAHIER-A5;Cahier A5 ligné;2,50;20;100;oui;{$csvCategories[0]}\nSTYLO-B;Stylo bille bleu;1,20;20;250;oui;{$csvCategories[1]}\n"), 'dry_run' => '0'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('created', 2);
    expectNoTradeWord('the import report', json_encode($articles->json(), JSON_UNESCAPED_UNICODE));
    post('/admin/import/clients', ['file' => $csv("code;societe;ville;code_postal;telephone\nC001;Librairie du Centre;Lyon;75002;0973000000\n"), 'dry_run' => '0'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('created', 1);

    $notebook = Article::query()->where('reference', 'CAHIER-A5')->sole();
    $pen = Article::query()->where('reference', 'STYLO-B')->sole();
    $client = Client::query()->where('company_name', 'Librairie du Centre')->sole();
    expect($notebook->attribute->variant)->toBe('standard')->and($notebook->attribute->needs_review)->toBeFalse()
        ->and($notebook->attribute->category)->toBe($categories[0]['value'] ?? null);

    // A seller takes an order, the operator approves and exports it.
    $uuid = (string) Str::uuid();
    actingAs($seller);
    $inertia('/orders/new');
    putJson("/api/orders/{$uuid}", orderPayload($client, [[$notebook, 10], [$notebook, 2, true], [$pen, 5]], Paytype::query()->where('is_enabled', true)->firstOrFail()))->assertCreated();
    actingAs($admin);
    postJson("/api/admin/orders/{$uuid}/approve")->assertOk();
    expectNoTradeWord('the orders CSV', get("/admin/orders/export.csv?uuids[]={$uuid}")->assertOk()->streamedContent());
    expectNoTradeWord('the orders workbook', workbookText(get("/admin/orders/export.xlsx?uuids[]={$uuid}")->assertOk()->streamedContent()));
    postJson('/api/admin/orders/mark-exported', ['uuids' => [$uuid]])->assertOk()->assertJsonPath('exported', [Order::query()->where('uuid', $uuid)->value('number')]);
    expect((float) $notebook->fresh()->stock_qty)->toBe(88.0);

    // The pages (their props, as the phone and the browser get them) and the files.
    foreach (['/', '/orders', '/orders/new', "/orders/{$uuid}", '/admin/orders', '/catalog', '/reports', '/reports/stock', '/reports/sales', '/reports/write-offs', '/admin/import',
        '/admin/article-attributes', '/admin/category-thresholds', '/admin/stock-adjustments', '/admin/clients'] as $page) {
        expectNoTradeWord("the props of {$page}", propTexts($inertia($page)->json('props')));
    }

    // The reports list the imported articles under sensible headings.
    $stock = collect((array) $inertia('/reports/stock')->json('props.report.tabs'))->where('kind', 'stock')
        ->mapWithKeys(fn (array $tab) => [$tab['label'] => array_column($tab['rows'], 'reference')])->all();
    expect($stock)->toBe($stockTabs);
    $sales = $inertia('/reports/sales?from=2026-01-01&to=2026-12-31')->json('props.report');
    expect($sales['cards']['quantity'])->toBe('17.00')
        ->and(array_column($sales['groups']['article'], 'label'))->toBe(['CAHIER-A5', 'STYLO-B'])
        ->and(array_column($sales['groups']['category'], 'label'))->toBe($salesGroups)
        ->and($inertia('/reports/sales')->json('props.catalog.has_categories'))->toBe($categories !== []);

    foreach (['/reports/stock.xlsx', '/reports/sales.xlsx', '/reports/write-offs.xlsx', '/catalog.xlsx'] as $file) {
        $response = get($file)->assertOk();
        expectNoTradeWord("the name of {$file}", (string) $response->headers->get('Content-Disposition'));
        expectNoTradeWord($file, workbookText($response->streamedContent()));
    }
})->with([
    // Without categories the grouping by category is not offered (has_categories false); its data holds one "~unclassified" row.
    'no categories: one list' => [[], ['', ''], ['ARTICLES' => ['CAHIER-A5', 'STYLO-B']], [null]],
    'two categories, imported with the articles' => [
        [['value' => 'CAHIERS', 'label' => 'Cahiers', 'group' => 'papeterie'], ['value' => 'STYLOS', 'label' => 'Stylos', 'group' => 'ecriture']],
        ['cahiers', 'Stylos'],
        ['CAHIERS' => ['CAHIER-A5'], 'STYLOS' => ['STYLO-B'], 'À CLASSER' => []],
        ['CAHIERS', 'STYLOS'],
    ],
]);

/*
| The repository holds no private key, no live payment secret and no path of a
| developer's machine. Every file git would ship is read as bytes (images and
| other binaries included); only what git ignores is skipped.
*/

/** A path under the project root, relative to it. */
function relativeToBase(string $path): string
{
    // Only the leading base path: a project mounted at /app must keep its app/ paths.
    return ltrim(str_starts_with($path, base_path()) ? substr($path, strlen(base_path())) : $path, '/');
}

/**
 * The repository's files as git would ship them: everything under the project root but the directories and
 * files .gitignore leaves out (dependencies, builds, caches, the private profile, local env files).
 *
 * @return list<string>
 */
function repositoryFiles(): array
{
    $skip = '#^(\.git|vendor|node_modules|storage|public/build|public/icons|public/hot|build-profile|profile|bootstrap/cache|\.phpunit\.cache|\.idea|\.vscode)(/|$)#';
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(base_path(), FilesystemIterator::SKIP_DOTS),
        fn (SplFileInfo $file) => preg_match($skip, relativeToBase($file->getPathname())) !== 1,
    )) as $file) {
        $relative = relativeToBase($file->getPathname());
        $name = basename($relative);
        if ((str_starts_with($name, '.env') && ! in_array($name, ['.env.example', '.env.production.example'], true))
            || in_array($name, ['.DS_Store', '.phpunit.result.cache'], true) || in_array($relative, ['public/sw.js', 'public/sw.js.map', 'public/storage'], true)) {
            continue;
        }
        $files[] = $relative;
    }
    sort($files);

    return $files;
}

it('holds no private key, live secret or machine path anywhere in the repository', function () {
    $found = [];
    $files = repositoryFiles();
    expect(count($files))->toBeGreaterThan(400)->and($files)->toContain('README.md', 'tests/Fixtures/profiles/fictional/logo.png');

    // The patterns are split so this file does not match itself.
    $patterns = ['-----BEGIN '.'(RSA |EC |OPENSSH |DSA )?PRIVATE KEY', 'sk'.'_live_', 'wh'.'sec_', '(?<![a-z0-9_])'.'sk-[a-z0-9]{16}',
        '(?<![a-z0-9_\'])/'.'users/[a-z]', '(?<![a-z0-9_])/'.'applications/', '(?<![a-z0-9_])/'.'home/[a-z]'];
    foreach ($files as $file) {
        $bytes = mb_strtolower((string) file_get_contents(base_path($file)), '8bit');
        foreach ($patterns as $pattern) {
            if (preg_match("#{$pattern}#", $bytes) === 1) {
                $found[] = "{$file}: {$pattern}";
            }
        }
    }

    expect($found)->toBe([]);
});
