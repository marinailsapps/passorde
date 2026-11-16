<?php

namespace App\Support\Profile;

use RuntimeException;

/**
 * The installation's private profile: a directory outside the repository with
 * a `profile.php` that returns the product settings this installation uses
 * (brand, messages, categories, labels…), merged over the neutral defaults of
 * config/passorde.php. The directory may also hold the logo the profile names
 * (brand.logo); the asset build reads both (App\Support\Profile\Brand,
 * scripts/profile-assets.php; docs/RUNBOOK.md "Installation profile").
 *
 * Only the sections in SECTIONS can be set by a profile; anything else is
 * ignored here and reported by `php artisan passorde:check-profile`.
 */
final class Profile
{
    public const FILE = 'profile.php';

    /** Profile sections of config/passorde.php, in their order there. */
    public const SECTIONS = ['brand', 'phone', 'messages', 'units', 'free_lines', 'client', 'catalog', 'evoliz', 'reports', 'texts', 'aliases'];

    /**
     * PASSORDE_PROFILE: unset or empty means base_path('profile'), used when it holds a profile.php;
     * "none" means no profile. Any other value names a directory that must hold one (required()).
     */
    public const NONE = 'none';

    /**
     * Set by load() while it reads config/passorde.php for a given directory: [directory, required].
     *
     * @var array{0: string|null, 1: bool}|null
     */
    private static ?array $forced = null;

    /**
     * config/passorde.php as it reads with the profile in this directory (null: the neutral defaults alone),
     * whatever PASSORDE_PROFILE and the config cache say. For the profile check.
     *
     * @return array<string, mixed>
     */
    public static function load(?string $directory, bool $required = false): array
    {
        self::$forced = [$directory, $required];

        try {
            return require config_path('passorde.php');
        } finally {
            self::$forced = null;
        }
    }

    /**
     * The profile directory, or null when PASSORDE_PROFILE is "none". Relative paths are read from the app's base path.
     */
    public static function directory(mixed $env): ?string
    {
        $value = trim((string) $env);

        if ($value === self::NONE) {
            return null;
        }

        if ($value === '') {
            return base_path('profile');
        }

        return str_starts_with($value, '/') ? rtrim($value, '/') : base_path($value);
    }

    /**
     * Whether PASSORDE_PROFILE names a directory explicitly: then a missing directory or profile.php is an error,
     * never a silent fall back to the neutral defaults (a typo in the path would change the app's texts and categories).
     */
    public static function required(mixed $env): bool
    {
        $value = trim((string) $env);

        return $value !== '' && $value !== self::NONE;
    }

    /**
     * The profile's settings as written, or null when the directory has no profile.php and none is required.
     *
     * @return array<mixed>|null
     */
    public static function read(?string $directory, bool $required = false): ?array
    {
        if ($directory !== null && $required && ! is_file($directory.'/'.self::FILE)) {
            throw new RuntimeException(is_dir($directory)
                ? sprintf('PASSORDE_PROFILE names %s, which holds no %s (set PASSORDE_PROFILE=none for the neutral defaults).', $directory, self::FILE)
                : sprintf('PASSORDE_PROFILE names %s, which does not exist (set PASSORDE_PROFILE=none for the neutral defaults).', $directory));
        }

        if ($directory === null || ! is_file($directory.'/'.self::FILE)) {
            return null;
        }

        $values = (static fn (string $file): mixed => require $file)($directory.'/'.self::FILE);

        if (! is_array($values)) {
            throw new RuntimeException(sprintf('The profile %s/%s must return an array (it returned %s).', $directory, self::FILE, get_debug_type($values)));
        }

        return $values;
    }

    /**
     * The defaults with the profile's sections merged over them, plus a "profile" entry saying where it came from.
     * In production (APP_ENV=production) PASSORDE_PROFILE must be stated, a directory or "none": an installation
     * never runs on the neutral defaults by accident (a missing mount would change its categories and texts).
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function apply(array $defaults, mixed $env, mixed $appEnv = null): array
    {
        $forced = self::$forced;
        if ($forced === null && $appEnv === 'production' && trim((string) $env) === '') {
            throw new RuntimeException('PASSORDE_PROFILE is not set: in production name the profile directory (e.g. /var/www/html/profile) or set it to "none" for the neutral defaults (docs/RUNBOOK.md "Installation profile").');
        }
        $directory = $forced !== null ? $forced[0] : self::directory($env);
        $required = $forced !== null ? $forced[1] : self::required($env);
        $values = self::read($directory, $required);

        foreach (self::SECTIONS as $section) {
            if (is_array($values) && array_key_exists($section, $values)) {
                $defaults[$section] = self::merge($defaults[$section] ?? null, $values[$section]);
            }
        }

        $defaults['profile'] = ['directory' => $directory, 'loaded' => $values !== null, 'required' => $required];

        return $defaults;
    }

    /**
     * Over an associative default, an associative value (or an empty one) merges key by key; anything else (a list,
     * a scalar, null, or any value over a list or null default) replaces the default whole.
     */
    public static function merge(mixed $default, mixed $value): mixed
    {
        $associative = fn (mixed $array): bool => is_array($array) && $array !== [] && ! array_is_list($array);

        if (! $associative($default) || ! is_array($value) || ($value !== [] && ! $associative($value))) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $default[$key] = array_key_exists($key, $default) ? self::merge($default[$key], $item) : $item;
        }

        return $default;
    }
}
