<?php

namespace App\Integrations\Sync\Sinks;

use App\Models\Article;
use App\Models\Client;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Adoption: an Evoliz record the app has never linked takes over
 * the row created without Evoliz (CSV import, typed in, standalone use) that has
 * the same key, instead of a second copy of it. Articles are keyed by reference,
 * clients by code, compared as the sync stores text (entities decoded, spaces
 * collapsed and trimmed), case-insensitively: a CSV export keeps the double
 * spaces that the sync folds.
 *
 * Only rows with no Evoliz id are ever candidates: a linked row is never adopted
 * nor changed here. Nothing is adopted when the key is empty, when two unlinked
 * rows share it, or when two unlinked Evoliz records of the same batch share it;
 * those records get a new row, and are counted as
 * ambiguous. An adopted row then takes Evoliz's values like any synced row, and
 * keeps what only the app has (article attributes, the optional client field, uuid, source).
 * The candidate is read again under lock when it is written (claim): a push that
 * linked it meanwhile wins. Ambiguity is judged within one page of records: two
 * Evoliz records with one key on different pages are not seen as ambiguous; the
 * first page's record adopts the row and the later one gets a row of its own.
 * docs/RUNBOOK.md "Evoliz synchronisation".
 */
final class Adoption
{
    /**
     * Unlinked articles that the given unknown records may adopt, by record id.
     *
     * @param  array<int, string|null>  $references  Evoliz reference by Evoliz article id, for records with no linked row
     * @return array<int, Article>
     */
    public static function articles(array $references): array
    {
        return self::match($references, 'articles', fn () => Article::query()->whereNull('evoliz_article_id')->get()->all(), fn (Article $article) => $article->reference);
    }

    /**
     * Unlinked clients that the given unknown records may adopt, by record id.
     *
     * @param  array<int, string|null>  $codes  Evoliz code by Evoliz client id, for records with no linked row
     * @return array<int, Client>
     */
    public static function clients(array $codes): array
    {
        return self::match($codes, 'clients', fn () => Client::query()->whereNull('evoliz_client_id')->whereNotNull('evoliz_code')->get()->all(), fn (Client $client) => $client->evoliz_code);
    }

    /**
     * The one unlinked article a record created by the app in Evoliz (a new article
     * from an order) should land on, or null.
     */
    public static function article(int $evolizId, ?string $reference): ?Article
    {
        return self::articles([$evolizId => $reference])[$evolizId] ?? null;
    }

    /**
     * The adoption candidate as it is now, locked until the caller's transaction ends, with the record's Evoliz id
     * set; null when a push linked it to another Evoliz id since the candidates were read (the record then gets
     * a row of its own). Linked meanwhile to this very id: that row, as any linked row.
     *
     * @template T of Article|Client
     *
     * @param  T  $candidate
     * @return T|null
     */
    public static function claim(Article|Client $candidate, int $evolizId): Article|Client|null
    {
        $column = $candidate instanceof Article ? 'evoliz_article_id' : 'evoliz_client_id';
        $fresh = $candidate->newQuery()->whereKey($candidate->getKey())->lockForUpdate()->first();
        $current = $fresh?->getAttribute($column);

        if ($fresh === null || ($current !== null && (int) $current !== $evolizId)) {
            // Counts only: no reference, code or name in the logs.
            Log::info('sync.adoption', ['kind' => $candidate instanceof Article ? 'articles' : 'clients', 'adopted' => 0, 'linked_meanwhile' => 1]);

            return null;
        }

        $fresh->setAttribute($column, $evolizId);

        return $fresh;
    }

    /**
     * @template T of Article|Client
     *
     * @param  array<int, string|null>  $keysById
     * @param  Closure(): list<T>  $unlinked  Every unlinked row (PHP compares the keys)
     * @param  Closure(T): ?string  $keyOf
     * @return array<int, T>
     */
    private static function match(array $keysById, string $kind, Closure $unlinked, Closure $keyOf): array
    {
        $normalised = [];

        foreach ($keysById as $id => $key) {
            $key = self::key($key);

            if ($key !== '') {
                $normalised[$id] = $key;
            }
        }

        if ($normalised === []) {
            return [];
        }

        // Two Evoliz records with one key: neither can tell which local row is its own.
        $evolizCounts = array_count_values($normalised);
        $local = [];

        foreach ($unlinked() as $row) {
            $local[self::key($keyOf($row))][] = $row;
        }
        $adopted = [];
        $ambiguous = 0;

        foreach ($normalised as $id => $key) {
            $rows = $local[$key] ?? [];

            if ($rows === []) {
                continue;
            }

            if ($evolizCounts[$key] > 1 || count($rows) > 1) {
                $ambiguous++;

                continue;
            }

            $adopted[$id] = $rows[0];
        }

        if ($adopted !== [] || $ambiguous > 0) {
            // Counts only: no reference, code or name in the logs.
            Log::info('sync.adoption', ['kind' => $kind, 'adopted' => count($adopted), 'ambiguous' => $ambiguous]);
        }

        return $adopted;
    }

    /**
     * The comparison key: what EvolizText::plain stores, lower-cased; '' when empty.
     */
    public static function key(?string $value): string
    {
        return mb_strtolower(EvolizText::plain($value) ?? '');
    }
}
