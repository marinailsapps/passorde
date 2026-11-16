<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Str;

/**
 * Guesses an article's category and variant from its reference and designation,
 * which is all Evoliz has (docs/RUNBOOK.md "Reports: stock by category"), with the
 * profile's lexicon (catalog.classifier; null: no guessing, the default variant):
 *
 *   - category: phrases, then whole words, aliases, a truncated word (4+ letters
 *     kept) or one typo in a long word (6+ letters); then a classification code
 *     naming a category; weak aliases (abbreviations that are also names) only
 *     when nothing else names one; a `secondary` category is dropped when another
 *     is named too;
 *   - variant: a classification code or `forced_variant_words` naming a variant
 *     that does not count decide at once (no category); without a category, the
 *     words of a variant that does not count, then `weak_classification_codes`;
 *     otherwise the first counting variant whose words appear, else the default;
 *   - needs_review when an article of a counting variant has no category or several.
 *
 * An entry of a word list is a word, or a list of words that must all appear.
 */
final class ArticleClassifier
{
    /**
     * @return array{category: string|null, variant: string, needs_review: bool}
     */
    public static function classify(?string $reference, ?string $designation, ?string $classification = null): array
    {
        $lexicon = config('passorde.catalog.classifier');
        $default = Catalog::defaultVariant();

        if (! is_array($lexicon)) {
            return ['category' => null, 'variant' => $default, 'needs_review' => Catalog::needsReview(null, $default)];
        }

        $tokens = self::tokens($reference.' '.$designation);
        $code = mb_strtoupper(trim((string) $classification));
        $strong = $code === '' ? null : ($lexicon['classification_codes'][$code] ?? null);
        $weak = $code === '' ? null : ($lexicon['weak_classification_codes'][$code] ?? null);

        $forced = Catalog::isVariant($strong) ? $strong : self::variantFrom((array) ($lexicon['forced_variant_words'] ?? []), $tokens, null);
        if ($forced !== null && ! Catalog::counts($forced)) {
            return ['category' => null, 'variant' => $forced, 'needs_review' => false];
        }

        $categoryCode = match (true) {
            ! Catalog::isVariant($strong) && is_string($strong) => $strong,
            ! Catalog::isVariant($weak) && is_string($weak) => $weak,
            default => null,
        };
        $categories = self::categories($tokens, $categoryCode, $lexicon);

        if ($categories === []) {
            $uncounted = self::variantFrom((array) ($lexicon['variant_words'] ?? []), $tokens, false);
            if ($uncounted !== null) {
                return ['category' => null, 'variant' => $uncounted, 'needs_review' => false];
            }
            if (Catalog::isVariant($weak) && ! Catalog::counts($weak)) {
                return ['category' => null, 'variant' => $weak, 'needs_review' => false];
            }
        }

        $variant = $forced ?? self::variantFrom((array) ($lexicon['variant_words'] ?? []), $tokens, true) ?? $default;

        return [
            'category' => count($categories) === 1 ? $categories[0] : null,
            'variant' => $variant,
            'needs_review' => count($categories) !== 1 && Catalog::categories() !== [],
        ];
    }

    /**
     * Upper-case ASCII words: HTML entities decoded, accents removed, cut at
     * anything that is not a letter or a digit.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $letters = (string) preg_replace('/[^\p{L}\p{Nd}]+/u', ' ', $decoded);
        $ascii = mb_strtoupper(Str::ascii($letters));

        return array_values(array_filter(explode(' ', $ascii), fn (string $token) => $token !== ''));
    }

    /**
     * The first variant whose words appear: any variant (counting null), or only counting / not counting ones.
     *
     * @param  array<string, mixed>  $words  variant => list of entries
     * @param  list<string>  $tokens
     */
    private static function variantFrom(array $words, array $tokens, ?bool $counting): ?string
    {
        foreach ($words as $variant => $entries) {
            if (! Catalog::isVariant($variant) || ($counting !== null && Catalog::counts($variant) !== $counting)) {
                continue;
            }
            foreach ((array) $entries as $entry) {
                if (array_diff((array) $entry, $tokens) === []) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /**
     * Full names first; then a classification code naming a category; then the
     * weak abbreviations.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $lexicon
     * @return list<string>
     */
    private static function categories(array $tokens, ?string $categoryCode, array $lexicon): array
    {
        $found = [];
        $phrase = ' '.implode(' ', $tokens).' ';
        $aliases = (array) ($lexicon['aliases'] ?? []);

        foreach ((array) ($lexicon['phrases'] ?? []) as $words => $category) {
            if (str_contains($phrase, ' '.$words.' ')) {
                $found[$category] = $category;
            }
        }

        foreach ($tokens as $token) {
            if (isset($aliases[$token])) {
                $found[$aliases[$token]] = $aliases[$token];

                continue;
            }

            if (strlen($token) < 4 || ctype_digit($token)) {
                continue;
            }

            foreach ((array) ($lexicon['words'] ?? []) as $word => $category) {
                $word = (string) $word;
                if ($token === $word
                    || str_starts_with($word, $token)
                    || (strlen($token) >= 6 && strlen($word) >= 6 && self::oneTypo($token, $word))) {
                    $found[$category] = $category;
                }
            }
        }

        if ($found === [] && $categoryCode !== null) {
            $found[$categoryCode] = $categoryCode;
        }

        if ($found === []) {
            $weak = (array) ($lexicon['weak_aliases'] ?? []);
            foreach ($tokens as $token) {
                if (isset($weak[$token])) {
                    $found[$weak[$token]] = $weak[$token];
                }
            }
        }

        // A secondary category named beside another is part of that other's name, not a second one.
        foreach ((array) ($lexicon['secondary'] ?? []) as $secondary) {
            if (isset($found[$secondary]) && count($found) > 1) {
                unset($found[$secondary]);
            }
        }

        return array_map('strval', array_values($found));
    }

    /**
     * Same word but for one wrong, missing or extra letter, or two swapped letters.
     */
    private static function oneTypo(string $a, string $b): bool
    {
        if (abs(strlen($a) - strlen($b)) > 1 || $a[0] !== $b[0]) {
            return false;
        }

        $m = strlen($a);
        $n = strlen($b);
        $d = [];
        for ($i = 0; $i <= $m; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $n; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$m][$n] <= 1;
    }
}
