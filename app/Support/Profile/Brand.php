<?php

namespace App\Support\Profile;

use RuntimeException;

/**
 * The installation's name, logo, colours and the names the phones store their
 * data under (profile sections `brand` and `phone`, config/passorde.php).
 *
 * The server reads them at run time (page titles, SMS, mails, Excel files). The
 * Vue bundle, the service worker, the web manifest, the icons and the colours are
 * fixed when the assets are built: scripts/profile-assets.php writes forBuild()
 * for the Vite build, which stamps it into public/build/profile.json (built()),
 * and `passorde:check-profile` compares that stamp with the running profile, so
 * assets built for another profile never go unnoticed (docs/RUNBOOK.md
 * "Installation profile"). forBuild() and buildErrors() use no Laravel: the
 * script runs without it.
 */
final class Brand
{
    /** The stamp of the asset build, under public/. */
    public const BUILT_FILE = 'build/profile.json';

    /** The keys of forBuild(), in the order the stamp holds them. */
    public const BUILD_KEYS = ['name', 'short_name', 'theme_color', 'accent', 'accent_strong', 'accent_soft', 'storage_prefix'];

    /** The `aliases` maps the phones need, fixed at build time like the storage prefix. */
    public const PHONE_ALIASES = ['client' => 'client', 'article' => 'article', 'totals' => 'totals', 'groupings' => 'groupings'];

    /** IndexedDB, Web Lock and localStorage names start with it: lower case letters, digits, "-" and "_". */
    public const STORAGE_PREFIX = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    /** phone.storage_prefix of config/passorde.php. */
    public const DEFAULT_STORAGE_PREFIX = 'passorde';

    /** Tests point it elsewhere: null means public_path(BUILT_FILE). */
    public static ?string $builtFile = null;

    public static function name(): string
    {
        return (string) config('passorde.brand.name');
    }

    public static function shortName(): string
    {
        return (string) config('passorde.brand.short_name');
    }

    public static function themeColor(): string
    {
        return (string) config('passorde.brand.theme_color');
    }

    /** The soft accent as Excel wants it (RRGGBB): the header row of the workbooks. */
    public static function accentSoftRgb(): string
    {
        return strtoupper(ltrim((string) config('passorde.brand.accent_soft'), '#'));
    }

    /**
     * What the asset build takes from these settings (written to brand.json, stamped into public/build/profile.json).
     *
     * @param  array<string, mixed>  $config  config/passorde.php as read
     * @return array<string, string|array<string, array<string, string>>>
     */
    public static function forBuild(array $config): array
    {
        if (($errors = self::buildErrors($config)) !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $values = [];
        foreach (self::BUILD_KEYS as $key) {
            $values[$key] = (string) ($key === 'storage_prefix' ? $config['phone']['storage_prefix'] : $config['brand'][$key]);
        }
        // The older names the phones' stored rows and saved links may carry (resources/js/offline/legacy.ts).
        $values['aliases'] = array_map(fn (string $map) => array_filter((array) ($config['aliases'][$map] ?? []), 'is_string'), self::PHONE_ALIASES);

        return $values;
    }

    /**
     * The logo file the profile names (brand.logo, in the profile directory), or null for the neutral logo.
     *
     * @param  array<string, mixed>  $config
     */
    public static function logoFile(array $config): ?string
    {
        $logo = $config['brand']['logo'] ?? null;
        $directory = $config['profile']['directory'] ?? null;

        return is_string($logo) && $logo !== '' && is_string($directory) ? $directory.'/'.$logo : null;
    }

    /**
     * What keeps these settings out of a build: the colours go into the stylesheet and the prefix into storage
     * names, so both must be exactly of their form; a named logo must be a PNG in the profile directory.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function buildErrors(array $config): array
    {
        $brand = is_array($config['brand'] ?? null) ? $config['brand'] : [];
        $errors = [];

        foreach (['name', 'short_name'] as $key) {
            if (! is_string($brand[$key] ?? null) || trim($brand[$key]) === '') {
                $errors[] = "brand.{$key} is required (a text).";
            }
        }
        foreach (['theme_color', 'accent', 'accent_strong', 'accent_soft'] as $key) {
            if (! is_string($brand[$key] ?? null) || preg_match('/^#[0-9a-fA-F]{6}$/', $brand[$key]) !== 1) {
                $errors[] = "brand.{$key} must be a colour like #334155.";
            }
        }

        $prefix = is_array($config['phone'] ?? null) ? ($config['phone']['storage_prefix'] ?? null) : null;
        if (! is_string($prefix) || preg_match(self::STORAGE_PREFIX, $prefix) !== 1) {
            $errors[] = 'phone.storage_prefix must be lower case letters, digits, "-" or "_" (at most 40), e.g. "passorde".';
        }

        $logo = $brand['logo'] ?? null;
        if ($logo !== null) {
            $file = self::logoFile($config);
            if (! is_string($logo) || $logo === '' || str_contains($logo, '/') || str_contains($logo, '\\')) {
                $errors[] = 'brand.logo must be the name of a PNG file in the profile directory (e.g. "logo.png"), or null.';
            } elseif ($file === null || ! is_file($file)) {
                $errors[] = "brand.logo \"{$logo}\" is not a file of the profile directory.";
            } elseif ((string) file_get_contents($file, false, null, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                $errors[] = "brand.logo \"{$logo}\" is not a PNG image.";
            }
        }

        return $errors;
    }

    /**
     * SHA-256 of the logo the assets should be built with: the profile's (brand.logo), else the neutral
     * resources/images/logo.png. The build stamps the same hash (scripts/brand.ts); null when the file is missing.
     *
     * @param  array<string, mixed>  $config
     */
    public static function logoHash(array $config): ?string
    {
        $file = self::logoFile($config) ?? base_path('resources/images/logo.png');

        return is_file($file) ? hash_file('sha256', $file) : null;
    }

    /**
     * What the assets in public/build were built with, or null when there is no stamp (no build, the dev server,
     * or a build older than this setting).
     *
     * @return array<string, string>|null
     */
    public static function built(): ?array
    {
        $file = self::$builtFile ?? public_path(self::BUILT_FILE);
        if (! is_file($file)) {
            return null;
        }

        $values = json_decode((string) file_get_contents($file), true);

        return is_array($values) ? array_map(fn (mixed $value) => is_scalar($value) ? (string) $value : (is_array($value) ? (string) json_encode($value) : ''), $values) : null;
    }
}
