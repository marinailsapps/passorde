<?php

/*
| What the asset build (npm run build) takes from the installation profile, written to
| a directory (default build-profile/, gitignored): brand.json (App\Support\Profile\Brand::forBuild:
| name, colours, the phones' storage prefix) and logo.png when the profile names a logo
| (otherwise none: the build uses resources/images/logo.png). Vite reads that directory
| (scripts/brand.ts); without it, the build is the neutral product.
|
| The profile is found exactly as the app finds it: PASSORDE_PROFILE from the environment,
| else from .env (empty: profile/ when it holds a profile.php; "none": no profile; a directory:
| it must hold one). With APP_ENV=production, PASSORDE_PROFILE must be stated (a directory or
| "none"), as at boot: the production image build sets it (docker/php/Dockerfile). Runs without
| Laravel or composer, which the image build does not have at this step.
|
|   php scripts/profile-assets.php [output directory]
|   docker compose exec app php scripts/profile-assets.php      (dev: the profile the app runs on)
*/

use App\Support\Profile\Brand;

$root = dirname(__DIR__);

// PASSORDE_PROFILE and APP_ENV from .env when the environment does not set them (as Laravel reads them).
$dotenv = is_file($root.'/.env') ? (string) file_get_contents($root.'/.env') : '';
foreach (['PASSORDE_PROFILE', 'APP_ENV'] as $name) {
    if (getenv($name) === false && preg_match('/^'.$name.'=(.*)$/m', $dotenv, $match) === 1) {
        putenv($name.'='.trim(trim($match[1]), '"\''));
    }
}

// The Laravel helpers config/passorde.php and Profile use, when Laravel is not loaded.
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}
if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return dirname(__DIR__).($path !== '' ? '/'.$path : '');
    }
}
if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return base_path('config'.($path !== '' ? '/'.$path : ''));
    }
}

require_once $root.'/app/Support/Profile/Profile.php';
require_once $root.'/app/Support/Profile/Brand.php';

$out = $argv[1] ?? $root.'/build-profile';

try {
    $config = require $root.'/config/passorde.php';
    $brand = Brand::forBuild($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'profile-assets: '.$e->getMessage().PHP_EOL);
    exit(1);
}

if (! is_dir($out) && ! mkdir($out, 0755, true)) {
    fwrite(STDERR, "profile-assets: cannot create {$out}".PHP_EOL);
    exit(1);
}

file_put_contents($out.'/brand.json', json_encode($brand, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$logo = Brand::logoFile($config);
if ($logo !== null) {
    copy($logo, $out.'/logo.png');
} elseif (is_file($out.'/logo.png')) {
    unlink($out.'/logo.png');
}

$profile = $config['profile']['loaded'] ? $config['profile']['directory'].'/profile.php' : 'no profile (the neutral defaults)';
fwrite(STDOUT, sprintf('profile-assets: %s → %s (%s, storage "%s", %s)', $profile, $out, $brand['name'], $brand['storage_prefix'], $logo !== null ? 'its logo' : 'the neutral logo').PHP_EOL);
