<?php

use App\Domain\Sms\OrderSms;
use App\Mail\OpsDigestMail;
use App\Models\Order;
use App\Support\Profile\Brand;
use App\Support\Profile\Profile;
use App\Support\Profile\ProfileCheck;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\get;

/*
| Package 2 of the neutral app: the name, logo, colours, texts and phone storage
| names come from the profile (brand, phone, messages), with "Passorde" and its
| neutral look as the defaults. The build-time parts are read at build by
| scripts/profile-assets.php (tested here) and scripts/brand.ts (brand.test.ts).
*/

const FICTIONAL = 'tests/Fixtures/profiles/fictional';

/** The settings with this profile directory (null: no profile) in place of the suite's pinned one. */
function brandWith(?string $directory): void
{
    config()->set('passorde', Profile::load($directory === null ? null : base_path($directory)));
    app()->forgetInstance('translator');
    app()->forgetInstance('translation.loader');
}

/**
 * scripts/profile-assets.php run as the image build runs it, into a fresh directory.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string, 2: string} exit code, output, output directory
 */
function profileAssets(array $env): array
{
    $out = sys_get_temp_dir().'/profile-assets-'.uniqid();
    $result = Process::path(base_path())->env([...$env, 'APP_ENV' => $env['APP_ENV'] ?? 'local'])->run(['php', 'scripts/profile-assets.php', $out]);

    return [$result->exitCode(), $result->output().$result->errorOutput(), $out];
}

it('keeps the neutral brand of the asset build equal to config/passorde.php', function () {
    $config = Profile::load(null);

    expect(json_decode((string) file_get_contents(resource_path('js/lib/brandDefaults.json')), true))->toBe(Brand::forBuild($config))
        ->and(Brand::forBuild($config))->toMatchArray(['name' => 'Passorde', 'short_name' => 'Passorde', 'storage_prefix' => Brand::DEFAULT_STORAGE_PREFIX])
        ->and(Brand::logoFile($config))->toBeNull();
});

it('names the app Passorde everywhere without a profile', function () {
    brandWith(null);
    $order = Order::factory()->create(['number' => 'C-2026-0001', 'total_quantity' => '3', 'total_ttc' => '12.50']);
    fileMode();

    get('/login')->assertOk()
        ->assertSee('<title inertia>Passorde</title>', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Passorde">', false)
        ->assertSee('<meta name="theme-color" content="#334155">', false);
    expect(OrderSms::text($order))->toBe('Passorde : votre commande n° C-2026-0001 (3 unités, 12,50 € TTC) est enregistrée. Merci !')
        ->and((new OpsDigestMail([['title' => 'x', 'lines' => []]]))->envelope()->subject)->toBe('Passorde : 1 point à vérifier')
        ->and((new OpsDigestMail([]))->render())->toContain('La vérification quotidienne de Passorde a relevé')
        ->and(Brand::accentSoftRgb())->toBe('E2E8F0');
});

it('takes every brand value from the profile', function () {
    brandWith(FICTIONAL);
    $order = Order::factory()->create(['number' => 'C-2026-0002', 'total_quantity' => '5', 'total_ttc' => '20', 'evoliz_delivery_number' => 'BL-1']);

    get('/login')->assertOk()
        ->assertSee('<title inertia>Exemple</title>', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Exemple">', false)
        ->assertSee('<meta name="theme-color" content="#2f5d3a">', false);
    expect(OrderSms::text($order))->toBe('Exemple : commande C-2026-0002 (5 plants, 20,00 € TTC) enregistrée, BL BL-1.')
        ->and((new OpsDigestMail([['title' => 'x', 'lines' => []]]))->envelope()->subject)->toBe('Exemple : 1 point à vérifier')
        ->and((new OpsDigestMail([]))->render())->toContain('La vérification quotidienne de Pépinière Exemple a relevé')
        ->and(Brand::name())->toBe('Pépinière Exemple')
        ->and(Brand::accentSoftRgb())->toBe('E6F0E8')
        ->and(Brand::logoFile(config('passorde')))->toBe(base_path(FICTIONAL.'/logo.png'));
});

it('writes what the asset build needs: neutral without a profile, the profile\'s values, storage prefix and logo with one', function () {
    [$code, $output, $out] = profileAssets(['PASSORDE_PROFILE' => 'none']);
    expect($code)->toBe(0, $output)
        ->and(json_decode((string) file_get_contents("{$out}/brand.json"), true))->toBe(json_decode((string) file_get_contents(resource_path('js/lib/brandDefaults.json')), true))
        ->and(file_exists("{$out}/logo.png"))->toBeFalse();

    [$code, $output, $out] = profileAssets(['PASSORDE_PROFILE' => base_path(FICTIONAL)]);
    expect($code)->toBe(0, $output)
        ->and(json_decode((string) file_get_contents("{$out}/brand.json"), true))->toBe([
            'name' => 'Pépinière Exemple', 'short_name' => 'Exemple', 'theme_color' => '#2f5d3a', 'accent' => '#2f5d3a',
            'accent_strong' => '#1f3f27', 'accent_soft' => '#e6f0e8', 'storage_prefix' => 'pepiniere',
            'aliases' => ['client' => [], 'article' => [], 'totals' => [], 'groupings' => []],
        ])
        ->and(md5_file("{$out}/logo.png"))->toBe(md5_file(base_path(FICTIONAL.'/logo.png')));
});

it('refuses a production asset build without a stated profile, and a named profile that is missing', function () {
    [$code, $output] = profileAssets(['PASSORDE_PROFILE' => '', 'APP_ENV' => 'production']);
    expect($code)->toBe(1)->and($output)->toContain('PASSORDE_PROFILE is not set: in production');

    [$code, $output] = profileAssets(['PASSORDE_PROFILE' => '/profile-not-given', 'APP_ENV' => 'production']);
    expect($code)->toBe(1)->and($output)->toContain('does not exist');

    expect(profileAssets(['PASSORDE_PROFILE' => 'none', 'APP_ENV' => 'production'])[0])->toBe(0);
});

it('checks the brand and phone keys of a profile', function () {
    $dir = sys_get_temp_dir().'/brand-profile-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/profile.php", "<?php return ['brand' => ['logo' => 'missing.png', 'accent' => 'teal', 'colour' => 1], 'phone' => ['storage_prefix' => 'Mon App']];");

    $errors = ProfileCheck::run($dir, false, true)['errors'];
    expect($errors)->toContain('Unknown key "brand.colour".')
        ->toContain('brand.accent must be a colour like #334155.')
        ->toContain('phone.storage_prefix must be lower case letters, digits, "-" or "_" (at most 40), e.g. "passorde".')
        ->toContain('brand.logo "missing.png" is not a file of the profile directory.');

    file_put_contents("{$dir}/missing.png", 'GIF89a');
    expect(ProfileCheck::run($dir, false, true)['errors'])->toContain('brand.logo "missing.png" is not a PNG image.')
        ->and(ProfileCheck::run(base_path(FICTIONAL), false, true)['errors'])->toBe([]);
});

it('fails the check when the assets were built for another storage prefix, and warns on another look', function () {
    $stamp = sys_get_temp_dir().'/brand-stamp-'.uniqid().'.json';
    Brand::$builtFile = $stamp;
    $fictional = Brand::forBuild(Profile::load(base_path(FICTIONAL)));

    file_put_contents($stamp, json_encode($fictional));
    expect(ProfileCheck::run(base_path(FICTIONAL), false, true))->toMatchArray(['errors' => [], 'warnings' => []]);

    // The neutral build deployed for an installation whose phones use another prefix: their data would be orphaned.
    file_put_contents($stamp, json_encode(Brand::forBuild(Profile::load(null))));
    $result = ProfileCheck::run(base_path(FICTIONAL), false, true);
    expect($result['errors'])->toBe(['The assets in public/build were built for phone.storage_prefix "passorde", this profile sets "pepiniere": the phones would no longer find their data and unsent orders. Build the assets with this profile (docs/RUNBOOK.md "Installation profile").'])
        ->and($result['warnings'])->toBe(['The assets in public/build were built with another brand.name, brand.short_name, brand.theme_color, brand.accent, brand.accent_strong, brand.accent_soft: the web manifest, icons or colours do not follow this profile until they are built with it.']);

    // Same storage, another colour: only a warning.
    file_put_contents($stamp, json_encode([...$fictional, 'accent' => '#000000']));
    expect(ProfileCheck::run(base_path(FICTIONAL), false, true)['errors'])->toBe([]);

    // The stamp holds the logo's SHA-256 (scripts/brand.ts): the profile's logo is fine, the neutral one a warning.
    $logo = hash_file('sha256', base_path(FICTIONAL.'/logo.png'));
    expect(Brand::logoHash(Profile::load(base_path(FICTIONAL))))->toBe($logo)
        ->and(Brand::logoHash(Profile::load(null)))->toBe(hash_file('sha256', resource_path('images/logo.png')));
    file_put_contents($stamp, json_encode([...$fictional, 'logo' => $logo]));
    expect(ProfileCheck::run(base_path(FICTIONAL), false, true))->toMatchArray(['errors' => [], 'warnings' => []]);
    file_put_contents($stamp, json_encode([...$fictional, 'logo' => hash_file('sha256', resource_path('images/logo.png'))]));
    expect(ProfileCheck::run(base_path(FICTIONAL), false, true))->toMatchArray([
        'errors' => [],
        'warnings' => ['The assets in public/build were built with another brand.logo: the web manifest, icons or colours do not follow this profile until they are built with it.'],
    ]);
});

it('puts the profile\'s older names into the asset build, and fails the check when the assets carry other ones', function () {
    $renamed = Profile::load(base_path('tests/Fixtures/profiles/renamed'));
    expect(Brand::forBuild($renamed)['aliases'])->toBe([
        'client' => ['extra_field' => 'member_code'],
        'article' => ['category' => 'family', 'variant' => 'kind'],
        'totals' => ['quantity' => 'pieces'],
        'groupings' => ['family' => 'category', 'kind' => 'variant'],
    ])->and(Brand::forBuild(Profile::load(null))['aliases'])->toBe(['client' => [], 'article' => [], 'totals' => [], 'groupings' => []]);

    $stamp = sys_get_temp_dir().'/brand-stamp-'.uniqid().'.json';
    Brand::$builtFile = $stamp;
    $error = 'The assets in public/build were built with other older names (aliases.client, article, totals or groupings): phones would read their stored data differently. Build the assets with this profile (docs/RUNBOOK.md "Installation profile").';

    file_put_contents($stamp, json_encode(Brand::forBuild($renamed)));
    expect(ProfileCheck::run(base_path('tests/Fixtures/profiles/renamed'), false, true)['errors'])->toBe([]);

    // The neutral build deployed for the renamed installation: its phones would no longer read their older rows.
    file_put_contents($stamp, json_encode(Brand::forBuild(Profile::load(null))));
    expect(ProfileCheck::run(base_path('tests/Fixtures/profiles/renamed'), false, true)['errors'])->toBe([$error])
        ->and(ProfileCheck::run(base_path(FICTIONAL), false, true)['errors'])->not->toContain($error);
    unlink($stamp);
});

it('refuses a profile that does not state its phones\' storage prefix once the installation has orders, and says nothing without a profile', function () {
    $error = fn (?string $directory) => collect(ProfileCheck::run($directory === null ? null : base_path($directory), true, $directory !== null)['errors'])
        ->contains(fn (string $line) => str_starts_with($line, 'phone.storage_prefix is not set, and this installation already has orders'));
    $anything = fn () => collect(ProfileCheck::run(null, true))->only(['errors', 'warnings'])->flatten()
        ->contains(fn (string $line) => str_contains($line, 'phone.storage_prefix'));

    expect($error('tests/Fixtures/profiles/pinned'))->toBeFalse();
    Order::factory()->create();
    expect($error('tests/Fixtures/profiles/pinned'))->toBeTrue()
        ->and($error(FICTIONAL))->toBeFalse()
        ->and($error(null))->toBeFalse()
        ->and($anything())->toBeFalse();
});
