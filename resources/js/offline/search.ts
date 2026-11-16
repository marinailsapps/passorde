import type { MirrorArticle, MirrorClient } from './db';
import { queryWords, rank, score, words } from '@/lib/fuzzyMatch';
import type { ArticleHit, ClientData } from '@/types';

/**
 * Searches over the phone's copy, used when the network is down or the API
 * search fails. Same inputs and result shapes as /api/clients/search and
 * /api/articles/search: 2 characters minimum, active rows only, 20 results,
 * ranked by lib/fuzzyMatch.ts exactly like the server (one typo allowed).
 */

export const MIN_LENGTH = 2;
export const LIMIT = 20;

export function normalise(value: string | null | undefined): string {
    return (value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const digits = (value: string | null | undefined): string => (value ?? '').replace(/\D/g, '');

/** searchExtra: the optional client field (extra_field) is searched, as App\Domain\Clients\ClientSearch does when the profile says so. */
export function searchLocalClients(clients: MirrorClient[], query: string, searchExtra = true): ClientData[] {
    const query_ = queryWords(query);
    if (query_.length === 0) return [];

    // Same scoring and order as the server (lib/fuzzyMatch.ts, app/Domain/Search/FuzzyMatch.php).
    return rank(
        clients.filter((client) => client.is_active),
        (client) =>
            score(
                query_,
                words(client.company_name),
                words([client.contact_name, client.town, client.postcode, searchExtra ? client.extra_field : null].join(' ')),
                digits(client.phone),
            ),
        (client) => client.company_name,
        (client) => client.id,
    )
        .slice(0, LIMIT)
        .map((client): ClientData => ({
            id: client.id,
            uuid: client.uuid,
            company_name: client.company_name,
            contact_name: client.contact_name,
            address_line: client.address_line,
            postcode: client.postcode,
            town: client.town,
            phone: client.phone,
            email: client.email,
            extra_field: client.extra_field,
            linked: client.linked,
        }));
}

export function searchLocalArticles(articles: MirrorArticle[], query: string, asOf: string | null): ArticleHit[] {
    const query_ = queryWords(query);
    if (query_.length === 0) return [];

    return rank(
        articles.filter((article) => article.is_active),
        (article) => score(query_, words(article.reference), words(article.designation), '', true),
        (article) => article.reference ?? '',
        (article) => article.id,
    )
        .slice(0, LIMIT)
        .map(
            (article): ArticleHit => ({
                id: article.id,
                reference: article.reference,
                designation: article.designation,
                unit_price_ht: article.unit_price_ht,
                vat_rate: article.vat_rate,
                is_stocked: article.is_stocked,
                stock_qty: article.stock_qty,
                reserved_qty: article.reserved_qty,
                stock_as_of: asOf,
            }),
        );
}
