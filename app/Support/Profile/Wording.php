<?php

namespace App\Support\Profile;

use Illuminate\Support\Str;

/**
 * The installation's words in the French texts (lang/fr.json): placeholders for
 * what one quantity, a free line and a category are called, filled from the
 * profile when the texts load, and the profile's `texts` section, which replaces
 * whole sentences by their English key where the placeholders do not give the
 * wording an installation wants. Laravel's translator gets them through
 * ProfileTranslationLoader; the phone through the shared prop `wording`
 * (resources/js/lib/i18n.ts, kept in the offline session). Both fill the
 * placeholders the same way: `:units` → the word, `:Units` → capitalised, `:UNITS`
 * → upper case.
 */
final class Wording
{
    /**
     * The placeholder words: unit / units (units.*), free (free_lines.label),
     * category / categories (catalog.category_name), item (catalog.new_article.label),
     * stock_title and write_offs_title (reports.*).
     *
     * @return array<string, string>
     */
    public static function words(): array
    {
        return [
            'unit' => (string) config('passorde.units.singular'),
            'units' => (string) config('passorde.units.plural'),
            'free' => (string) config('passorde.free_lines.label'),
            'category' => (string) config('passorde.catalog.category_name.singular'),
            'categories' => (string) config('passorde.catalog.category_name.plural'),
            'item' => (string) (config('passorde.catalog.new_article.label') ?? ''),
            // The report titles: one setting for the page, the workbook, its sheet and its print header.
            'stock_title' => (string) config('passorde.reports.stock_title'),
            'write_offs_title' => (string) config('passorde.reports.write_offs_title'),
        ];
    }

    /**
     * Older keys this profile's `texts` may still use (aliases.texts, old => current).
     *
     * @return array<string, string>
     */
    public static function oldTextKeys(): array
    {
        return array_filter((array) config('passorde.aliases.texts', []), fn ($new, $old) => is_string($old) && is_string($new), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Whole French sentences of the profile, by English key.
     *
     * @return array<string, string>
     */
    public static function texts(): array
    {
        $texts = array_filter((array) config('passorde.texts', []), fn ($text, $key) => is_string($key) && is_string($text), ARRAY_FILTER_USE_BOTH);

        // A key the profile still names by an older name (aliases.texts) is read as the current one.
        foreach (self::oldTextKeys() as $old => $new) {
            if (isset($texts[$old])) {
                $texts[$new] ??= $texts[$old];
                unset($texts[$old]);
            }
        }

        return $texts;
    }

    /**
     * The JSON lines of a locale with the profile's sentences laid over and every placeholder word filled in.
     *
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    public static function apply(array $lines): array
    {
        return self::applyWith($lines, self::words(), self::texts());
    }

    /**
     * apply() for given words and sentences; twin of applyWording() in resources/js/lib/i18n.ts,
     * both pinned by tests/Fixtures/wording-cases.json.
     *
     * @param  array<string, mixed>  $lines
     * @param  array<string, string>  $words
     * @param  array<string, string>  $texts
     * @return array<string, mixed>
     */
    public static function applyWith(array $lines, array $words, array $texts): array
    {
        $replace = self::replacements($words);

        foreach ([...$lines, ...$texts] as $key => $line) {
            $lines[$key] = is_string($line) ? strtr($line, $replace) : $line;
        }

        return $lines;
    }

    /**
     * For the shared prop `wording`.
     *
     * @return array{words: array<string, string>, texts: array<string, string>}
     */
    public static function view(): array
    {
        return ['words' => self::words(), 'texts' => self::texts()];
    }

    /**
     * @param  array<string, string>  $words
     * @return array<string, string>
     */
    private static function replacements(array $words): array
    {
        $replace = [];
        foreach ($words as $name => $word) {
            $replace[':'.$name] = $word;
            $replace[':'.Str::ucfirst($name)] = Str::ucfirst($word);
            $replace[':'.Str::upper($name)] = Str::upper($word);
        }

        return $replace;
    }
}
