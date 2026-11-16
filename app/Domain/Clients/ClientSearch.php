<?php

namespace App\Domain\Clients;

use App\Domain\Search\FuzzyMatch;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client directory search (FuzzyMatch): words of the company name first,
 * then contact, town, postcode and the optional client field when the
 * profile makes it searchable (ClientExtraField), phone digits; one typo
 * allowed in words of 4+ letters; best match first. The directory is about a
 * thousand rows, so candidates are scored in PHP rather than in SQL. The phone
 * ranks its offline results the same way (resources/js/offline/search.ts).
 */
class ClientSearch
{
    public const MIN_LENGTH = FuzzyMatch::MIN_LENGTH;

    /** Search terms are capped at 100 characters (validation) and this many words. */
    public const MAX_WORDS = FuzzyMatch::MAX_WORDS;

    /**
     * Ids of the clients of $query that match, best first.
     *
     * @param  Builder<Client>  $query
     * @return list<int>
     */
    public static function rankedIds(Builder $query, ?string $term, ?int $limit = null): array
    {
        $words = self::queryWords($term);
        if ($words === []) {
            return [];
        }

        $rows = $query->toBase()->get(['id', 'company_name', 'contact_name', 'town', 'postcode', 'extra_field', 'phone']);
        $searchExtra = ClientExtraField::searchable();

        $ranked = FuzzyMatch::rank(
            $rows,
            fn (object $row) => FuzzyMatch::score(
                $words,
                FuzzyMatch::words($row->company_name),
                FuzzyMatch::words(implode(' ', [$row->contact_name, $row->town, $row->postcode, $searchExtra ? $row->extra_field : null])),
                (string) preg_replace('/\D/', '', (string) $row->phone),
            ),
            fn (object $row) => (string) $row->company_name,
            fn (object $row) => (int) $row->id,
        );

        $ids = array_map(fn (object $row) => (int) $row->id, $ranked);

        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    /**
     * Restricts $query to the matching clients, best first (lists, filters).
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function apply(Builder $query, ?string $term): Builder
    {
        // Too short: no filter. Long enough but nothing searchable ("%_"): no result.
        if (mb_strlen(trim((string) $term)) < self::MIN_LENGTH) {
            return $query;
        }

        return self::inOrder($query, self::rankedIds(clone $query, $term));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return Builder<TModel>
     */
    public static function inOrder(Builder $query, array $ids): Builder
    {
        if ($ids === []) {
            return $query->whereRaw('false');
        }

        $table = $query->getModel()->getTable();

        // The ids are integers from the database: safe to inline.
        return $query->whereIn("{$table}.id", $ids)
            ->orderByRaw('array_position(ARRAY['.implode(',', $ids)."]::bigint[], {$table}.id)");
    }

    /**
     * @return list<string>
     */
    public static function queryWords(?string $term): array
    {
        return FuzzyMatch::queryWords($term);
    }
}
