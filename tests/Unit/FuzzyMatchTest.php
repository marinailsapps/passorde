<?php

use App\Domain\Search\FuzzyMatch;

/*
| The cases are shared with resources/js/lib/fuzzyMatch.test.ts: the server
| and the phone must rank the same way.
*/

$fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/search-cases.json'), true, flags: JSON_THROW_ON_ERROR);

/**
 * @param  array<string, mixed>  $fixture
 * @return list<int>
 */
function rankFixture(array $fixture, string $kind, string $query, bool $searchExtra = true): array
{
    $words = FuzzyMatch::queryWords($query);

    $ranked = $kind === 'clients'
        ? FuzzyMatch::rank(
            $fixture['clients'],
            fn (array $c) => FuzzyMatch::score(
                $words,
                FuzzyMatch::words($c['company_name']),
                // The optional client field is read unless the case says the profile does not search it (ClientSearch).
                FuzzyMatch::words(implode(' ', [$c['contact_name'], $c['town'], $c['postcode'], $searchExtra ? $c['extra_field'] : null])),
                (string) preg_replace('/\D/', '', (string) $c['phone']),
            ),
            fn (array $c) => $c['company_name'],
            fn (array $c) => $c['id'],
        )
        : FuzzyMatch::rank(
            $fixture['articles'],
            fn (array $a) => FuzzyMatch::score($words, FuzzyMatch::words($a['reference']), FuzzyMatch::words($a['designation']), '', true),
            fn (array $a) => $a['reference'],
            fn (array $a) => $a['id'],
        );

    return array_map(fn (array $item) => $item['id'], $ranked);
}

it('ranks the shared search cases', function (string $kind, string $query, array $expect, bool $searchExtra) use ($fixture) {
    expect(rankFixture($fixture, $kind, $query, $searchExtra))->toBe($expect);
})->with(array_map(fn (array $case) => [$case['kind'], $case['query'], $case['expect'], ($case['extra_field_searchable'] ?? true) !== false], $fixture['cases']));

it('folds accents and ligatures like the phone', function () {
    expect(FuzzyMatch::words('EURL Étang Béral / LOT : 2796 Œuvre Søren ½ € ² ×'))
        ->toBe(['eurl', 'etang', 'beral', 'lot', '2796', 'oeuvre', 'soren']);
});
