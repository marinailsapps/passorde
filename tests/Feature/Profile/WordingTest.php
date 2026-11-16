<?php

use App\Models\User;
use App\Support\Profile\Profile;
use App\Support\Profile\ProfileCheck;
use App\Support\Profile\Wording;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/*
| the French texts name the installation's
| units, free lines, categories and new article through placeholders filled from
| the profile, and a profile may replace whole sentences (`texts`). The server's
| translator and the phone (shared prop `wording`, resources/js/lib/i18n.ts) fill
| them the same way, pinned by tests/Fixtures/wording-cases.json.
*/

/** The translator again, as it loads with config('passorde') from this fixture profile (null: the neutral defaults). */
function withWordingProfile(?string $name): void
{
    config()->set('passorde', Profile::load($name === null ? null : base_path("tests/Fixtures/profiles/{$name}")));
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');
    app()->setLocale('fr');
}

$cases = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/wording-cases.json'), true, flags: JSON_THROW_ON_ERROR);

it('fills the placeholders as the phone does', function (array $case) {
    expect(Wording::applyWith($case['lines'], $case['words'], $case['texts']))->toBe($case['expected']);
})->with(array_combine(array_column($cases, 'name'), array_map(fn (array $case) => [$case], $cases)));

it('speaks neutrally without a profile', function () {
    withWordingProfile(null);

    expect(__('Category'))->toBe('Catégorie')
        ->and(__('All categories'))->toBe('Toutes les catégories')
        ->and(__(':reference, free line', ['reference' => 'ABC']))->toBe('ABC, offert')
        ->and(__('Total quantity'))->toBe('Quantité totale (unités)')
        ->and(__('A free line needs a paid line of the same article.'))->toBe('Une ligne offerte doit accompagner une ligne payante du même article.')
        ->and(__('Unknown category ":value": ignored.', ['value' => 'X']))->toBe('Catégorie « X » inconnue : ignorée.');
});

it('keeps the plural of the bulk "mark as exported"', function () {
    $lines = json_decode((string) file_get_contents(lang_path('fr.json')), true);

    expect($lines['Mark as exported'])->toBe('Marquer comme exportée|Marquer comme exportées')
        ->and(trans_choice('Mark as exported', 1))->toBe('Marquer comme exportée')
        ->and(trans_choice('Mark as exported', 3))->toBe('Marquer comme exportées');
});

it('bundles the neutral words for the phone as config/passorde.php has them', function () {
    withWordingProfile(null);

    // resources/js/lib/i18n.ts falls back on them for an offline session older than the prop.
    expect(json_decode((string) file_get_contents(resource_path('js/lib/wordingDefaults.json')), true))->toBe(Wording::words());
});

it('takes the words and the sentences of the profile', function () {
    withWordingProfile('fictional');
    config()->set('passorde.catalog.category_name', ['singular' => 'essence', 'plural' => 'essences']);
    config()->set('passorde.texts', ['Create the article' => 'Créer la variété']);
    app()->forgetInstance('translator');

    expect(__('Category'))->toBe('Essence')
        ->and(__(':count :units', ['count' => 3]))->toBe('3 plants')
        ->and(__('+ New article'))->toBe('+ Nouvelle variété')
        ->and(__('Create the article'))->toBe('Créer la variété');
});

it('shares the wording and the catalogue with the pages', function (?string $profile, bool $newArticle, array $variants, string $category) {
    withWordingProfile($profile);

    actingAs(User::factory()->create())->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('wording.words.category', $category)
        ->where('catalog.new_article', $newArticle)
        ->where('catalog.variants', $variants));
})->with([
    'neutral' => [null, false, [['value' => 'standard', 'label' => 'Standard', 'name' => 'Standard', 'counts' => true, 'own_tab' => false]], 'catégorie'],
    'fictional' => ['fictional', true, [
        ['value' => 'racine', 'label' => 'Racines nues', 'name' => 'Racines nues', 'counts' => true, 'own_tab' => false],
        ['value' => 'godet', 'label' => 'Godet', 'name' => 'Godet', 'counts' => true, 'own_tab' => false],
        ['value' => 'outil', 'label' => 'Outil', 'name' => 'Outil', 'counts' => false, 'own_tab' => true],
    ], 'catégorie'],
]);

it('checks the sentences of a profile', function () {
    $directory = sys_get_temp_dir().'/passorde-texts-'.Str::random(6);
    mkdir($directory);
    file_put_contents("{$directory}/profile.php", '<?php return '.var_export(['texts' => ['Category' => 'Essence', 'No such key' => 'Rien', 'Variant' => '']], true).';');

    $result = ProfileCheck::run($directory, false);

    expect($result['errors'])->toContain('texts "Variant" must be a French text.')
        ->and($result['warnings'])->toContain('texts "No such key" is not a key of lang/fr.json: it is never shown.')
        ->and(implode("\n", $result['errors']))->not->toContain('"Category"');
});

it('keeps the French texts of the neutral app free of installation words in the keys the profile can change', function () {
    $lines = json_decode((string) file_get_contents(lang_path('fr.json')), true);
    $moved = ['+ free line', '+ New article', ':count :units', ':reference, free line', 'A free line needs a paid line of the same article.', 'Create the article',
        'Creating the article in Evoliz…', 'Name of the article', 'Quantities', 'Free lines', 'New article', 'Variant', 'Total quantity', 'free line',
        'Thresholds per category', 'All categories', 'No category', 'Category', 'Category…', 'Set this category', 'Category of :reference', 'Category threshold',
        'Quantity to keep in stock per article. The stock report suggests ordering when the stock to come falls below it. A threshold set on an article (Article attributes) wins over its category’s.',
        'Unknown category ":value": ignored.', 'Creating the article…', 'Without network, a new article cannot be created.'];

    foreach ($moved as $key) {
        expect($lines)->toHaveKey($key);
        expect(installationWordIn($lines[$key]))->toBeNull("An installation word in \"{$key}\"");
    }
});

it('reads a texts key by an older name of the profile (aliases.texts), and the check says to rename it', function () {
    renamedInstallation();
    config()->set('passorde.texts', ['sales.sheet.kind' => 'Nu-Motte']);
    app()->forgetInstance('translator');

    expect(__('sales.sheet.variant'))->toBe('Nu-Motte')
        ->and(Wording::view()['texts'])->toBe(['sales.sheet.variant' => 'Nu-Motte']);

    // The current name wins when the profile has both.
    config()->set('passorde.texts', ['sales.sheet.kind' => 'Ancien', 'sales.sheet.variant' => 'Nouveau']);
    expect(Wording::texts())->toBe(['sales.sheet.variant' => 'Nouveau']);

    $directory = sys_get_temp_dir().'/passorde-texts-'.Str::random(6);
    mkdir($directory);
    file_put_contents("{$directory}/profile.php", '<?php return '.var_export(['texts' => ['sales.sheet.kind' => 'Nu-Motte'], 'aliases' => ['texts' => ['sales.sheet.kind' => 'sales.sheet.variant']]], true).';');
    $result = ProfileCheck::run($directory, false);

    expect($result['errors'])->toBe([])
        ->and($result['warnings'])->toContain('texts "sales.sheet.kind" is an older name of "sales.sheet.variant" (aliases.texts): still read as it, rename it in the profile.');

    // Without that alias the old key is an unknown one.
    config()->set('passorde.aliases.texts', []);
    expect(Wording::texts())->toBe(['sales.sheet.kind' => 'Ancien', 'sales.sheet.variant' => 'Nouveau']);
});
