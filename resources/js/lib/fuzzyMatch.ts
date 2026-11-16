/**
 * Search scoring, identical to app/Domain/Search/FuzzyMatch.php (read its
 * comment for the rules): the phone ranks its offline results exactly like
 * the server. Change both together, and the shared cases in
 * tests/Fixtures/search-cases.json. No browser globals (runs in Vitest too).
 */

export const MAX_WORDS = 6;
export const MIN_LENGTH = 2;

// Letters that do not decompose under NFD, folded as PHP's Str::ascii does.
const LIGATURES: Record<string, string> = { œ: 'oe', æ: 'ae', ß: 'ss', ø: 'o', ł: 'l', đ: 'd' };

export function words(text: string | null | undefined): string[] {
    // Separators first (°, €, ², punctuation), then accents on letters only, as the server does.
    const ascii = (text ?? '')
        .replace(/[^\p{L}\p{Nd}]+/gu, ' ')
        .toLowerCase()
        .replace(/[œæßøłđ]/g, (letter) => LIGATURES[letter])
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');
    return ascii.split(/[^a-z0-9]+/).filter(Boolean);
}

const isDigits = (word: string): boolean => /^[0-9]+$/.test(word);

/** The words of a search box, as FuzzyMatch::queryWords(): a number typed with spaces is one number. */
export function queryWords(term: string): string[] {
    const trimmed = term.trim();
    if (trimmed.length < MIN_LENGTH) return [];
    if (/^[\d\s.\-/+()]+$/.test(trimmed)) return [trimmed.replace(/\D/g, '')];
    return words(trimmed).slice(0, MAX_WORDS);
}

/** Optimal string alignment distance: Levenshtein plus one edit for two swapped neighbours (as FuzzyMatch::distance). */
function distance(a: string, b: string): number {
    const d: number[][] = Array.from({ length: a.length + 1 }, (_, i) => Array.from({ length: b.length + 1 }, (_, j) => (i === 0 ? j : j === 0 ? i : 0)));
    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
        }
    }
    return d[a.length][b.length];
}

/** Same first letter; an extra letter in the typed word only counts from 5 letters. */
function startsWithOneTypo(token: string, word: string): boolean {
    if (token === '' || token[0] !== word[0]) return false;
    const prefixes = word.length >= 5 ? [word.length, word.length + 1, word.length - 1] : [word.length, word.length + 1];
    return prefixes.some((prefix) => prefix <= token.length && distance(word, token.slice(0, prefix)) <= 1);
}

/** points: equal, prefix, contains (0: not allowed), one typo. */
function wordScore(word: string, tokens: string[], points: [number, number, number, number]): number {
    let best = 0;
    const typos = word.length >= 4 && !isDigits(word);

    for (const token of tokens) {
        if (token === word) return points[0];
        if (token.startsWith(word)) {
            best = Math.max(best, points[1]);
        } else if (points[2] > 0 && word.length >= 3 && token.includes(word)) {
            best = Math.max(best, points[2]);
        } else if (typos && best < points[3] && startsWithOneTypo(token, word)) {
            best = points[3];
        }
    }
    return best;
}

export function score(query: string[], primary: string[], secondary: string[], digits = '', secondaryContains = false): number {
    if (query.length === 0) return 0;

    let total = 0;
    for (const word of query) {
        const best = Math.max(
            wordScore(word, primary, [100, 90, 60, 50]),
            wordScore(word, secondary, [80, 72, secondaryContains ? 48 : 0, 40]),
            digits !== '' && word.length >= 3 && isDigits(word) && digits.includes(word) ? 80 : 0,
        );
        if (best === 0) return 0;
        total += best;
    }

    const phrase = query.join(' ');
    const name = primary.join(' ');
    if (name === phrase) total += 100;
    else if (name.startsWith(`${phrase} `)) total += 50;

    return total;
}

/** Best first; ties by primary field, then id (as the server sorts). */
export function rank<T>(items: T[], scoreOf: (item: T) => number, nameOf: (item: T) => string, idOf: (item: T) => number): T[] {
    return items
        .map((item) => ({ item, score: scoreOf(item), name: words(nameOf(item)).join(' ') }))
        .filter((entry) => entry.score > 0)
        .sort((a, b) => b.score - a.score || (a.name < b.name ? -1 : a.name > b.name ? 1 : 0) || idOf(a.item) - idOf(b.item))
        .map((entry) => entry.item);
}
