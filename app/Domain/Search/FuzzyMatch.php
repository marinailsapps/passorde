<?php

namespace App\Domain\Search;

use Illuminate\Support\Str;

/**
 * Scores how well a search query matches a client or an article. The same
 * algorithm runs on the phone (resources/js/lib/fuzzyMatch.ts) so offline
 * results match the server's: change both together, and the shared cases in
 * tests/Fixtures/search-cases.json.
 *
 * Text is lower-cased, accents removed, and cut into words at anything that
 * is not a letter or a digit. Every word of the query must match a word of
 * the item:
 *
 *   word of the primary field (company name, article reference):
 *     equal 100, starts with it 90, contains it (3+ characters) 60,
 *     starts with it give or take one typo 50 (words of 4+ letters, same
 *     first letter; a typo is one wrong, missing or extra letter or two
 *     neighbouring letters swapped; an extra letter only from 5 letters)
 *   word of the other fields: equal 80, starts with it 72, contains it 48
 *     (only where $secondaryContains), one typo 40
 *   phone digits containing a query number of 3+ digits: 80
 *
 * The item's score is the sum over the query words, plus 100 when the primary
 * field is the whole query or 50 when it starts with the query's whole words.
 * 0 means no match.
 * Results sort by score, then primary field, then id.
 */
final class FuzzyMatch
{
    public const MAX_WORDS = 6;

    public const MIN_LENGTH = 2;

    /**
     * The words of a search box: none under MIN_LENGTH characters, at most
     * MAX_WORDS. A number typed with spaces or dots ("06 12 34") is one number.
     *
     * @return list<string>
     */
    public static function queryWords(?string $term): array
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }
        if (preg_match('/^[\d\s.\-\/+()]+$/', $term) === 1) {
            return [(string) preg_replace('/\D/', '', $term)];
        }

        return array_slice(self::words($term), 0, self::MAX_WORDS);
    }

    /**
     * @return list<string>
     */
    public static function words(?string $text): array
    {
        // Separators first (°, €, ², punctuation), then accents on letters only,
        // so both sides fold exactly the same characters.
        $letters = (string) preg_replace('/[^\p{L}\p{Nd}]+/u', ' ', (string) $text);
        $ascii = mb_strtolower(Str::ascii($letters));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $ascii) ?: [], fn (string $word) => $word !== ''));
    }

    /**
     * @param  list<string>  $query  words() of the query, at most MAX_WORDS
     * @param  list<string>  $primary  words() of the primary field
     * @param  list<string>  $secondary  words() of the other fields
     * @param  string  $digits  phone digits ('' when none)
     */
    public static function score(array $query, array $primary, array $secondary, string $digits = '', bool $secondaryContains = false): int
    {
        if ($query === []) {
            return 0;
        }

        $total = 0;
        foreach ($query as $word) {
            $best = max(
                self::wordScore($word, $primary, [100, 90, 60, 50]),
                self::wordScore($word, $secondary, [80, 72, $secondaryContains ? 48 : 0, 40]),
                $digits !== '' && strlen($word) >= 3 && ctype_digit($word) && str_contains($digits, $word) ? 80 : 0,
            );

            if ($best === 0) {
                return 0;
            }
            $total += $best;
        }

        $phrase = implode(' ', $query);
        $name = implode(' ', $primary);
        if ($name === $phrase) {
            $total += 100;
        } elseif (str_starts_with($name, $phrase.' ')) {
            $total += 50;
        }

        return $total;
    }

    /**
     * Best first; ties by primary field, then id (as the phone sorts).
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): int  $scoreOf
     * @param  callable(T): string  $nameOf
     * @param  callable(T): int  $idOf
     * @return list<T>
     */
    public static function rank(iterable $items, callable $scoreOf, callable $nameOf, callable $idOf): array
    {
        $entries = [];
        foreach ($items as $item) {
            $score = $scoreOf($item);
            if ($score > 0) {
                $entries[] = ['item' => $item, 'score' => $score, 'name' => implode(' ', self::words($nameOf($item))), 'id' => $idOf($item)];
            }
        }

        // strcmp, not <=>: "10" <=> "9" compares as numbers in PHP, never in JavaScript.
        usort($entries, fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: (strcmp($a['name'], $b['name']) <=> 0) ?: ($a['id'] <=> $b['id']));

        return array_map(fn (array $entry) => $entry['item'], $entries);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array{0: int, 1: int, 2: int, 3: int}  $points  equal, prefix, contains (0: not allowed), one typo
     */
    private static function wordScore(string $word, array $tokens, array $points): int
    {
        $best = 0;
        $length = strlen($word);
        $typos = $length >= 4 && ! ctype_digit($word);

        foreach ($tokens as $token) {
            if ($token === $word) {
                return $points[0];
            }
            if (str_starts_with($token, $word)) {
                $best = max($best, $points[1]);
            } elseif ($points[2] > 0 && $length >= 3 && str_contains($token, $word)) {
                $best = max($best, $points[2]);
            } elseif ($typos && $best < $points[3] && self::startsWithOneTypo($token, $word)) {
                $best = $points[3];
            }
        }

        return $best;
    }

    /**
     * The token starts with the word give or take one typo (see the class comment).
     */
    private static function startsWithOneTypo(string $token, string $word): bool
    {
        if ($token === '' || $token[0] !== $word[0]) {
            return false;
        }

        $length = strlen($word);
        $prefixes = $length >= 5 ? [$length, $length + 1, $length - 1] : [$length, $length + 1];

        foreach ($prefixes as $prefix) {
            if ($prefix <= strlen($token) && self::distance($word, substr($token, 0, $prefix)) <= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Optimal string alignment distance: Levenshtein where swapping two
     * neighbouring letters also counts as one edit (same code in fuzzyMatch.ts).
     */
    private static function distance(string $a, string $b): int
    {
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
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$m][$n];
    }
}
