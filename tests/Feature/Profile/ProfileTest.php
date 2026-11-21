<?php

use App\Models\Article;
use App\Models\Client;
use App\Support\Profile\Profile;
use App\Support\Profile\ProfileCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/*
| config/passorde.php (neutral defaults), the
| private profile merged over it, and `passorde:check-profile`.
*/

function fixtureProfile(string $name): string
{
    return base_path("tests/Fixtures/profiles/{$name}");
}

/**
 * An article with stored category and variant values (raw: the app's enums do not know fictional ones).
 */
function storedAttributes(?string $category, ?string $variant): void
{
    DB::table('article_attributes')->insert([
        'article_id' => Article::factory()->create()->id,
        'category' => $category,
        'variant' => $variant,
        'needs_review' => false,
        'source' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('loading', function () {
    it('runs on the neutral defaults when there is no profile', function () {
        $config = Profile::load(null);

        expect($config['profile'])->toBe(['directory' => null, 'loaded' => false, 'required' => false])
            ->and($config['brand']['name'])->toBe('Passorde')
            ->and($config['catalog']['categories'])->toBe([])
            ->and($config['client']['extra_field'])->toBeNull();
    });

    it('reads PASSORDE_PROFILE from the environment, and runs every test on the pinned fixture whatever it says', function () {
        $env = getenv('PASSORDE_PROFILE');
        expect(config('passorde.profile'))->toEqual(Profile::load(Profile::directory($env), Profile::required($env))['profile'])
            ->and(config('passorde'))->toEqual([...Profile::load(fixtureProfile('pinned')), 'profile' => config('passorde.profile')]);
    });

    it('uses no installation word in the defaults', function () {
        expect(installationWords())->not->toBeEmpty()
            ->and(installationWordIn(json_encode(Profile::load(null), JSON_UNESCAPED_UNICODE)))->toBeNull();
    });

    it('merges a profile over the defaults: sections key by key, lists whole', function () {
        $config = Profile::load(fixtureProfile('fictional'));

        expect($config['profile'])->toBe(['directory' => fixtureProfile('fictional'), 'loaded' => true, 'required' => false])
            ->and($config['brand']['name'])->toBe('Pépinière Exemple')
            ->and(array_column($config['catalog']['categories'], 'value'))->toBe(['POMMIER', 'POIRIER', 'ERABLE'])
            ->and(array_column($config['catalog']['variants'], 'value'))->toBe(['racine', 'godet', 'outil'])
            ->and($config['client']['extra_field']['csv'])->toBe('numero_adherent');
    });

    it('keeps the defaults a profile leaves out', function () {
        expect(Profile::merge(['a' => 1, 'b' => ['c' => 2, 'd' => 3]], ['b' => ['d' => 4]]))->toBe(['a' => 1, 'b' => ['c' => 2, 'd' => 4]])
            ->and(Profile::merge(['a' => 1], []))->toBe(['a' => 1])
            ->and(Profile::merge([['value' => 'x']], [['value' => 'y']]))->toBe([['value' => 'y']])
            ->and(Profile::merge([['value' => 'x']], []))->toBe([])
            ->and(Profile::merge(null, ['label' => 'N°']))->toBe(['label' => 'N°'])
            ->and(Profile::merge(['label' => 'N°'], null))->toBeNull();
    });

    it('ignores at boot the keys a profile may not set', function () {
        $config = Profile::load(fixtureProfile('typos'));

        expect($config)->not->toHaveKey('admin')->not->toHaveKey('brnad');
    });

    it('reads PASSORDE_PROFILE as a directory', function (string $env, ?string $expected) {
        // Relative expectations are read from the app's base path.
        $expected = $expected === null || str_starts_with($expected, '/') ? $expected : base_path($expected);

        expect(Profile::directory($env))->toBe($expected);
    })->with([
        'empty: profile/' => ['', 'profile'],
        'none' => ['none', null],
        'absolute' => ['/srv/profile/', '/srv/profile'],
        'relative to the app' => ['tests/Fixtures/profiles/fictional', 'tests/Fixtures/profiles/fictional'],
    ]);

    it('stops the boot when profile.php returns no array', function () {
        Profile::load(fixtureProfile('not-an-array'));
    })->throws(RuntimeException::class, 'must return an array');

    it('runs on the defaults when the default directory has no profile.php', function () {
        expect(Profile::load(base_path('tests/Fixtures/profiles'))['profile']['loaded'])->toBeFalse()
            ->and(Profile::apply([], '')['profile'])->toMatchArray(['directory' => base_path('profile'), 'required' => false]);
    });

    it('requires a profile.php only where PASSORDE_PROFILE names a directory', function (mixed $env, bool $required) {
        expect(Profile::required($env))->toBe($required);
    })->with([
        'unset' => [null, false],
        'empty' => ['', false],
        'none' => ['none', false],
        'absolute' => ['/srv/profile', true],
        'relative' => ['profile', true],
    ]);

    it('stops the boot when PASSORDE_PROFILE names a directory that does not exist or holds no profile.php', function (string $env, string $message) {
        expect(fn () => Profile::apply([], $env))->toThrow(RuntimeException::class, $message);
    })->with([
        'missing directory' => ['/srv/no-such-profile', 'PASSORDE_PROFILE names /srv/no-such-profile, which does not exist'],
        'no profile.php' => ['tests/Fixtures/profiles', 'which holds no profile.php'],
    ]);
});

describe('check', function () {
    it('passes the neutral defaults on an empty database', function () {
        fileMode();

        expect(ProfileCheck::run(null))->toBe(['errors' => [], 'warnings' => [], 'loaded' => false]);
    });

    it('warns, with Evoliz on, that no payment term is set', function () {
        expect(ProfileCheck::run(null)['warnings'])->toBe(['evoliz.payterm_id is not set: with Evoliz on, approved orders fail until it is (an id of GET /payterms).']);
    });

    it('passes a complete profile whose values match the database', function () {
        storedAttributes('POMMIER', 'godet');
        storedAttributes(null, 'outil');

        expect(ProfileCheck::run(fixtureProfile('fictional')))->toBe(['errors' => [], 'warnings' => [], 'loaded' => true]);
    });

    it('names unknown keys, missing entries and broken references', function () {
        $errors = ProfileCheck::run(fixtureProfile('typos'), database: false)['errors'];

        expect($errors)->toContain(
            'Unknown key "brnad": a profile sets only brand, phone, messages, units, free_lines, client, catalog, evoliz, reports, texts, aliases.',
            'Unknown key "admin": a profile sets only brand, phone, messages, units, free_lines, client, catalog, evoliz, reports, texts, aliases.',
            'Unknown key "brand.colour".',
            'Unknown key "catalog.categories[0].colour".',
            'Unknown key "catalog.classifier.synonyms".',
            'brand.name is required (a text).',
            'brand.accent must be a colour like #334155.',
            'catalog.categories: "POMMIER" is listed twice.',
            'catalog.categories[2].value "UNE CATEGORIE AU NOM BEAUCOUP TROP LONG" is longer than the 30 characters it is stored in.',
            'catalog.categories[3].label is required (a text).',
            'catalog.categories[3].group "vergers" is not a key of catalog.category_groups.',
            'catalog.variants needs at least one variant.',
            'catalog.classifier.words "CERISIER" names "CERISIER", which is not in catalog.categories.',
        );
    });

    it('reports stored categories and variants the profile does not list', function () {
        storedAttributes('CERISIER', 'godet');
        storedAttributes('CERISIER', 'bouture');
        DB::table('category_thresholds')->insert(['category' => 'NOYER', 'threshold' => 5, 'created_at' => now(), 'updated_at' => now()]);

        expect(ProfileCheck::run(fixtureProfile('fictional'))['errors'])->toBe([
            '2 row(s) of article_attributes.category hold "CERISIER", which catalog.categories does not list.',
            '1 row(s) of category_thresholds.category hold "NOYER", which catalog.categories does not list.',
            '1 row(s) of article_attributes.variant hold "bouture", which catalog.variants does not list.',
        ]);
    });

    it('compares nothing on a database without any table, as before the first deploy of a new installation, and says so', function () {
        // Inside the test's transaction: rolled back afterwards.
        DB::statement('DROP SCHEMA public CASCADE');
        DB::statement('CREATE SCHEMA public');

        expect(ProfileCheck::run(fixtureProfile('fictional')))->toBe([
            'errors' => [],
            'warnings' => ['The database is empty (no table yet): stored values not compared.'],
            'loaded' => true,
        ]);
    });

    it('compares the stored values on a database with tables but no migrations table', function () {
        storedAttributes('CERISIER', 'godet');
        DB::statement('DROP TABLE migrations');

        expect(ProfileCheck::run(fixtureProfile('fictional'))['errors'])
            ->toBe(['1 row(s) of article_attributes.category hold "CERISIER", which catalog.categories does not list.'])
            ->and(ProfileCheck::run(fixtureProfile('fictional'))['warnings'])->toBe([]);
    });

    it('warns when an optional field switched off still has data', function () {
        fileMode();
        Client::factory()->create(['extra_field' => 'X-1']);

        expect(ProfileCheck::run(null)['warnings'])->toBe(['client.extra_field is off, but 1 client(s) have a value in it: it would no longer show.']);
    });

    it('warns about a stock tab nothing fills', function () {
        $directory = sys_get_temp_dir().'/profile-'.Str::random(8);
        mkdir($directory);
        file_put_contents($directory.'/profile.php', "<?php\n\$p = require '".fixtureProfile('fictional')."/profile.php';\n"
            ."\$p['catalog']['category_groups'][] = ['key' => 'haies', 'label' => 'HAIES'];\nreturn \$p;\n");
        copy(fixtureProfile('fictional').'/logo.png', $directory.'/logo.png');

        try {
            expect(ProfileCheck::run($directory, database: false))->toMatchArray([
                'errors' => [],
                'warnings' => ['catalog.category_groups "haies" has no category and no variant: its tab stays empty.'],
            ]);
        } finally {
            unlink($directory.'/profile.php');
            unlink($directory.'/logo.png');
            rmdir($directory);
        }
    });
});

describe('command', function () {
    it('says the neutral defaults apply and succeeds on an empty database', function () {
        fileMode();
        artisan('passorde:check-profile', ['--path' => 'none'])
            ->expectsOutputToContain('No profile: the neutral defaults of config/passorde.php apply.')
            ->expectsOutputToContain('The profile is fine.')
            ->assertSuccessful();
    });

    it('checks the profile of another directory', function () {
        artisan('passorde:check-profile', ['--path' => 'tests/Fixtures/profiles/fictional'])
            ->expectsOutputToContain('Profile: '.fixtureProfile('fictional').'/profile.php')
            ->assertSuccessful();
    });

    it('fails and lists the errors of a broken profile', function () {
        artisan('passorde:check-profile', ['--path' => 'tests/Fixtures/profiles/typos', '--no-database' => true])
            ->expectsOutputToContain('Unknown key "brnad"')
            ->expectsOutputToContain('error(s)')
            ->assertFailed();
    });

    it('fails on a named directory without a profile', function (string $path, string $message) {
        artisan('passorde:check-profile', ['--path' => $path])->expectsOutputToContain($message)->assertFailed();
    })->with([
        'missing directory' => ['/srv/no-such-profile', 'which does not exist'],
        'no profile.php' => ['tests/Fixtures/profiles', 'which holds no profile.php'],
    ]);

    it('fails on a profile that does not load', function () {
        artisan('passorde:check-profile', ['--path' => 'tests/Fixtures/profiles/not-an-array'])
            ->expectsOutputToContain('profile.php could not be read')
            ->assertFailed();
    });

    it('fails when the database holds values the profile does not list, unless asked not to compare', function () {
        storedAttributes('CERISIER', 'standard');

        artisan('passorde:check-profile', ['--path' => 'none'])->expectsOutputToContain('"CERISIER"')->assertFailed();
        artisan('passorde:check-profile', ['--path' => 'none', '--no-database' => true])->assertSuccessful();
    });
});
