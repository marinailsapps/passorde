import { describe, expect, it } from 'vitest';

import fixture from '../../../tests/Fixtures/wording-cases.json';
import { applyWording, setWording, t, tc } from './i18n';

const cases = fixture as unknown as { name: string; words: Record<string, string>; texts: Record<string, string>; lines: Record<string, string>; expected: Record<string, string> }[];

describe('tc', () => {
    it('takes the singular for 0 and 1, the plural from 2, like trans_choice in French', () => {
        expect(tc(':count entries', 0)).toBe('0 saisie');
        expect(tc(':count entries', 1)).toBe('1 saisie');
        expect(tc(':count entries', 2)).toBe('2 saisies');
        expect(tc(':count orders', 12)).toBe('12 commandes');
    });

    it('fills the other placeholders too', () => {
        expect(tc(':count attempts, last error: :error', 1, { error: 'HTTP 503' })).toBe('1 tentative, dernière erreur : HTTP 503');
    });

    it('falls back to the key, with a single form, when there is no translation', () => {
        expect(tc(':count widgets', 3)).toBe('3 widgets');
    });
});

describe('t', () => {
    it('still replaces placeholders', () => {
        expect(t('Hello :name', { name: 'Ana' })).toBe('Bonjour Ana');
    });
});

// Twin of App\Support\Profile\Wording::applyWith(), pinned by the same fixture.
describe('applyWording', () => {
    for (const testCase of cases) {
        it(testCase.name, () => {
            expect(applyWording(testCase.lines, { words: testCase.words, texts: testCase.texts })).toEqual(testCase.expected);
        });
    }
});

describe('setWording', () => {
    it('lays the installation wording over lang/fr.json for t()', () => {
        setWording({ words: { unit: 'plant', units: 'plants', free: 'plant offert', category: 'essence', categories: 'essences', item: 'Nouvelle variété' }, texts: { 'Create the article': 'Créer la variété' } });

        expect(t('Category')).toBe('Essence');
        expect(t(':count :units', { count: 2 })).toBe('2 plants');
        expect(t('+ New article')).toBe('+ Nouvelle variété');
        expect(t('Create the article')).toBe('Créer la variété');

        // An older offline session has no wording: the neutral words, never a raw placeholder.
        setWording(null);
        expect(t('Category')).toBe('Catégorie');
        expect(t('Free lines')).toBe('Unités offertes');
    });
});
