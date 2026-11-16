/**
 * Rows a phone stored under older field names (an installation that renamed fields keeps them in its profile,
 * `aliases`, fixed in the assets at build time: resources/js/lib/brand.ts) are read under the current names
 * (Dexie "reading" hooks in db.ts), so the code never sees an old row, and the stored row itself is left as it is.
 * Outbox entries are not touched at all: the server accepts their old names (App\Http\Api\ApiAliases).
 * In a new installation every map is empty and nothing is renamed.
 * No browser globals: runs in the service worker and in Vitest too.
 */
import { brand } from '../lib/brand';

/** current => old */
export type NameMap = Readonly<Record<string, string>>;

export interface LegacyNames {
    client: NameMap;
    article: NameMap;
    totals: NameMap;
}

/** The installation's older names, as the assets were built. */
export const names: LegacyNames = brand.aliases;

/** A grouping saved under an older name (a link, the report's ?group=) gives the current one; aliases.groupings is old => current. */
export function currentGrouping(param: string | null, groupings: NameMap = brand.aliases.groupings): string | null {
    return param === null ? null : (groupings[param] ?? param);
}

type Loose = Record<string, unknown>;

const isObject = (value: unknown): value is Loose => typeof value === 'object' && value !== null && !Array.isArray(value);

/** Gives an object the current names its old ones stand for; the current name wins when both are there. */
export function withNewNames<T>(row: T, map: NameMap): T {
    if (!isObject(row)) return row;
    const values: Loose = row;
    for (const [next, old] of Object.entries(map)) {
        if (!(next in values) && old in values) values[next] = values[old];
    }
    return row;
}

/** Same, for the keys of a correction ({field: {from, to}}): the old key is replaced where it stands. */
function renameKeys(values: unknown, map: NameMap): unknown {
    if (!isObject(values) || !Object.values(map).some((old) => old in values)) return values;
    const back = Object.fromEntries(Object.entries(map).map(([next, old]) => [old, next]));
    return Object.fromEntries(Object.entries(values).filter(([key]) => !(key in back && back[key] in values)).map(([key, value]) => [back[key] ?? key, value]));
}

/** A row of the client mirror. */
export const readClient = <T>(row: T, maps: LegacyNames = names): T => withNewNames(row, maps.client);

/** A row of the article mirror. */
export const readArticle = <T>(row: T, maps: LegacyNames = names): T => withNewNames(row, maps.article);

/** A local order: its client, its totals and its pending correction. */
export function readOrder<T>(row: T, maps: LegacyNames = names): T {
    if (!isObject(row)) return row;
    withNewNames(row.client, maps.client);
    withNewNames(row.totals, maps.totals);
    if (isObject(row.client_change)) row.client_change.changes = renameKeys(row.client_change.changes, maps.client);
    return row;
}

/** An order form draft kept in sessionStorage across the reload that brings the new bundle. */
export function readDraft<T>(draft: T, maps: LegacyNames = names): T {
    if (!isObject(draft) || !isObject(draft.selection)) return draft;
    withNewNames(draft.selection.client, maps.client);
    if ('changes' in draft.selection) draft.selection.changes = renameKeys(draft.selection.changes, maps.client);
    return draft;
}
