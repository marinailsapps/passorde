<?php

namespace App\Domain\Catalog;

use App\Domain\Clients\ClientSearch;
use App\Domain\Search\FuzzyMatch;
use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catalogue search (FuzzyMatch): words of the reference first, then of the
 * designation (codes and lot numbers included, also found inside a
 * word); one typo allowed in words of 4+ letters; best match first. The phone
 * ranks its offline results the same way (resources/js/offline/search.ts).
 */
class ArticleSearch
{
    public const MIN_LENGTH = FuzzyMatch::MIN_LENGTH;

    /**
     * Ids of the articles of $query that match, best first.
     *
     * @param  Builder<Article>  $query
     * @return list<int>
     */
    public static function rankedIds(Builder $query, ?string $term, ?int $limit = null): array
    {
        $words = self::queryWords($term);
        if ($words === []) {
            return [];
        }

        $rows = $query->toBase()->get(['articles.id', 'articles.reference', 'articles.designation']);

        $ranked = FuzzyMatch::rank(
            $rows,
            fn (object $row) => FuzzyMatch::score($words, FuzzyMatch::words($row->reference), FuzzyMatch::words($row->designation), '', true),
            fn (object $row) => (string) $row->reference,
            fn (object $row) => (int) $row->id,
        );

        $ids = array_map(fn (object $row) => (int) $row->id, $ranked);

        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    /**
     * Restricts $query to the matching articles, best first.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public static function apply(Builder $query, ?string $term): Builder
    {
        // Too short: no filter. Long enough but nothing searchable ("%_"): no result.
        if (mb_strlen(trim((string) $term)) < self::MIN_LENGTH) {
            return $query;
        }

        return ClientSearch::inOrder($query, self::rankedIds(Article::query()->whereIn('articles.id', (clone $query)->select('articles.id')), $term));
    }

    /**
     * @return list<string>
     */
    public static function queryWords(?string $term): array
    {
        return FuzzyMatch::queryWords($term);
    }
}
