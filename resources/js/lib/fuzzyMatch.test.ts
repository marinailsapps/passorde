import { describe, expect, it } from 'vitest';

import fixture from '../../../tests/Fixtures/search-cases.json';
import { queryWords, rank, score, words } from './fuzzyMatch';

// The cases are shared with tests/Unit/FuzzyMatchTest.php: the phone ranks like the server.
type Client = (typeof fixture.clients)[number];
type Article = (typeof fixture.articles)[number];

/** searchExtra: the optional client field is read (ClientSearch, offline/search.ts), unless a case says otherwise. */
function rankFixture(kind: string, query: string, searchExtra = true): number[] {
    const q = queryWords(query);
    if (kind === 'clients') {
        return rank<Client>(
            fixture.clients,
            (c) => score(q, words(c.company_name), words([c.contact_name, c.town, c.postcode, searchExtra ? c.extra_field : null].join(' ')), (c.phone ?? '').replace(/\D/g, '')),
            (c) => c.company_name,
            (c) => c.id,
        ).map((c) => c.id);
    }
    return rank<Article>(
        fixture.articles,
        (a) => score(q, words(a.reference), words(a.designation), '', true),
        (a) => a.reference,
        (a) => a.id,
    ).map((a) => a.id);
}

describe('search ranking (shared cases)', () => {
    for (const testCase of fixture.cases) {
        it(`${testCase.kind}: "${testCase.query}" (${testCase.why})`, () => {
            const searchExtra = !('extra_field_searchable' in testCase) || testCase.extra_field_searchable !== false;
            expect(rankFixture(testCase.kind, testCase.query, searchExtra)).toEqual(testCase.expect);
        });
    }

    it('folds accents and ligatures like the server', () => {
        expect(words('EURL Étang Béral / LOT : 2796 Œuvre Søren ½ € ² ×')).toEqual(['eurl', 'etang', 'beral', 'lot', '2796', 'oeuvre', 'soren']);
    });
});
