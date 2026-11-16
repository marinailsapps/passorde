import fr from '../../../lang/fr.json';
import type { Wording } from '@/types';

import defaultWords from './wordingDefaults.json';

/**
 * The neutral words of config/passorde.php (pinned equal by tests/Feature/Profile/WordingTest.php), for a page
 * without the prop: a phone whose offline session is older than it never shows a raw ":Units".
 */
const NEUTRAL: Wording = { words: defaultWords, texts: {} };

let messages: Record<string, string> = applyWording(fr, NEUTRAL);
let applied = 'null';

/**
 * Translate an English key using lang/fr.json (shared with Laravel's __()).
 * Placeholders use Laravel's syntax: t('Hello :name', { name: 'Ana' }).
 */
export function t(key: string, replacements: Record<string, string | number> = {}): string {
    return replace(messages[key] ?? key, replacements);
}

/**
 * Count-dependent text: the French value holds "singular|plural", like
 * Laravel's trans_choice(), with the same French rule (0 and 1 take the
 * singular). `:count` is filled in: tc(':count entries', 1) → "1 saisie".
 */
export function tc(key: string, count: number, replacements: Record<string, string | number> = {}): string {
    const forms = (messages[key] ?? key).split('|');
    const form = forms.length > 1 && count !== 0 && count !== 1 ? forms[1] : forms[0];

    return replace(form, { count, ...replacements });
}

/**
 * The installation's wording (shared prop `wording`, kept in the offline session):
 * from the next t() on, the texts are lang/fr.json with the profile's sentences
 * laid over and the placeholder words filled in, as the server does; without
 * one (an older offline session), the neutral words.
 */
export function setWording(wording: Wording | null | undefined): void {
    const key = JSON.stringify(wording ?? null);
    if (key === applied) return;
    applied = key;
    messages = applyWording(fr, wording ?? NEUTRAL);
}

/**
 * Twin of App\Support\Profile\Wording::apply(): `:units` → the word, `:Units` →
 * capitalised, `:UNITS` → upper case; the longest placeholder wins where several start.
 */
export function applyWording(lines: Record<string, string>, wording: Wording): Record<string, string> {
    const words: Record<string, string> = {};
    for (const [name, word] of Object.entries(wording.words)) {
        words[`:${name}`] = word;
        words[`:${ucfirst(name)}`] = ucfirst(word);
        words[`:${name.toUpperCase()}`] = word.toUpperCase();
    }
    const names = Object.keys(words).sort((a, b) => b.length - a.length);
    const pattern = names.length > 0 ? new RegExp(names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g') : null;

    const out: Record<string, string> = {};
    for (const [key, line] of Object.entries({ ...lines, ...wording.texts })) {
        out[key] = pattern ? line.replace(pattern, (name) => words[name]) : line;
    }

    return out;
}

function ucfirst(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function replace(message: string, replacements: Record<string, string | number>): string {
    for (const [name, value] of Object.entries(replacements)) {
        message = message.replaceAll(`:${name}`, String(value));
    }

    return message;
}
