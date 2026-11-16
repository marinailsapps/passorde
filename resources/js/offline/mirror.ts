import type { LocalOrder, MirrorArticle, MirrorClient, MirrorDelivery, MirrorDeliveryLine, UserDb } from './db';
import type { Paytype } from '@/types';

/**
 * Keeps the phone's copy of the directory, the catalogue, the paytypes and the
 * user's own orders up to date with the delta endpoints (docs/API.md,
 * "Offline mirror"). The first load pulls everything; later loads ask for rows
 * changed since the last `until`, minus a minute of overlap. Clients are fully
 * reloaded once a day, which drops the few the server deletes outright.
 *
 * The Evoliz BL history (thousands of rows) comes last and resumes where it
 * stopped: each page is stored as it arrives, with the cursor, so a first load
 * cut short by a closed app picks up at the next refresh.
 */

export type Getter = <T>(path: string, query: Record<string, string | undefined>) => Promise<T>;

interface DeltaPage<T> {
    data: T[];
    meta: { per_page: number; next_cursor: string | null; until: string };
    reservations?: Record<string, string>;
}

export const EPOCH = '1970-01-01T00:00:00Z';
export const OVERLAP_MS = 60_000;
export const FULL_RELOAD_MS = 24 * 3600_000;

/** Meta key of the time of the last complete sync (ISO), shown as the age of the data. */
export const SYNCED_AT = 'mirror.syncedAt';

type ServerClient = Omit<MirrorClient, 'key' | 'pending'>;
type ServerOrder = LocalOrder;

async function pull<T>(db: UserDb, get: Getter, name: string, path: string, full: boolean, apply: (page: DeltaPage<T>) => Promise<void>): Promise<void> {
    const previous = await db.getMeta<string>(`${name}.until`);
    const since = full || !previous ? EPOCH : new Date(new Date(previous).getTime() - OVERLAP_MS).toISOString();
    let until: string | undefined;
    let cursor: string | undefined;

    do {
        const page = await get<DeltaPage<T>>(path, { updated_since: since, until, cursor });
        until = page.meta.until;
        await apply(page);
        cursor = page.meta.next_cursor ?? undefined;
    } while (cursor);

    await db.setMeta(`${name}.until`, until);
}

export async function syncClients(db: UserDb, get: Getter, now = Date.now()): Promise<void> {
    const lastFull = await db.getMeta<number>('clients.fullAt');
    const full = lastFull === undefined || now - lastFull >= FULL_RELOAD_MS;
    const seen = new Set<string>();

    await pull<ServerClient>(db, get, 'clients', '/api/clients', full, async (page) => {
        const rows: MirrorClient[] = page.data.map((client) => ({ ...client, key: `id:${client.id}` }));
        rows.forEach((row) => seen.add(row.key));
        await db.transaction('rw', db.clients, async () => {
            await db.clients.bulkPut(rows);
            // A client typed on the phone is now a real one.
            const typed = rows.map((row) => row.uuid).filter((uuid): uuid is string => !!uuid);
            if (typed.length) await db.clients.bulkDelete(typed.map((uuid) => `uuid:${uuid}`));
        });
    });

    if (full) {
        const stale = (await db.clients.toCollection().primaryKeys()).filter((key) => key.startsWith('id:') && !seen.has(key));
        await db.clients.bulkDelete(stale);
        await db.setMeta('clients.fullAt', now);
    }
}

/** Bumped when the article rows gain fields: the next sync reloads them all once (3: category and variant). */
export const ARTICLE_FIELDS = 3;

export async function syncArticles(db: UserDb, get: Getter): Promise<void> {
    let reservations: Record<string, string> | undefined;
    const full = (await db.getMeta<number>('articles.fields')) !== ARTICLE_FIELDS;

    await pull<MirrorArticle>(db, get, 'articles', '/api/articles', full, async (page) => {
        await db.articles.bulkPut(page.data);
        reservations = page.reservations ?? reservations;
    });

    await db.setMeta('articles.fields', ARTICLE_FIELDS);

    // Reservations move with orders, not with articles: refresh them all.
    if (reservations) {
        const map = reservations;
        await db.articles.toCollection().modify((article) => {
            article.reserved_qty = map[String(article.id)] ?? '0.00';
        });
    }
}

export async function syncPaytypes(db: UserDb, get: Getter): Promise<void> {
    const { data } = await get<{ data: Paytype[] }>('/api/paytypes', {});
    await db.transaction('rw', db.paytypes, async () => {
        await db.paytypes.clear();
        await db.paytypes.bulkPut(data);
    });
}

export async function syncOrders(db: UserDb, get: Getter): Promise<void> {
    await pull<ServerOrder>(db, get, 'orders', '/api/orders', false, async (page) => {
        await db.transaction('rw', db.orders, db.outbox, async () => {
            for (const row of page.data) {
                // Changes still waiting in the outbox win until they are sent.
                if ((await db.outbox.where('uuid').equals(row.uuid).count()) > 0) continue;

                if (row.deleted_at) {
                    await db.orders.delete(row.uuid);
                    continue;
                }

                const local = await db.orders.get(row.uuid);
                // Keep a refusal notice (for example an edit that came too late) until it is dismissed.
                const notice = local?.local_state === 'unsent' ? local.local_error : null;
                await db.orders.put({ ...row, local_state: notice ? 'unsent' : 'synced', local_error: notice ?? null });
            }
        });
    });
}

interface Progress {
    since: string;
    until: string;
    cursor: string;
}

/** Like pull(), but stores the cursor after every page so an interrupted load resumes. */
async function pullResumable<T>(db: UserDb, get: Getter, name: string, path: string, apply: (page: DeltaPage<T>) => Promise<void>): Promise<void> {
    const saved = await db.getMeta<Progress>(`${name}.progress`);
    const previous = await db.getMeta<string>(`${name}.until`);
    const since = saved?.since ?? (previous ? new Date(new Date(previous).getTime() - OVERLAP_MS).toISOString() : EPOCH);
    let until = saved?.until;
    let cursor = saved?.cursor;

    do {
        const page = await get<DeltaPage<T>>(path, { updated_since: since, until, cursor });
        until = page.meta.until;
        await apply(page);
        cursor = page.meta.next_cursor ?? undefined;
        await db.setMeta(`${name}.progress`, cursor ? { since, until, cursor } : undefined);
    } while (cursor);

    await db.setMeta(`${name}.until`, until);
}

/** Every Evoliz BL; a deleted one goes with its lines. */
export async function syncDeliveries(db: UserDb, get: Getter): Promise<void> {
    await pullResumable<MirrorDelivery>(db, get, 'deliveries', '/api/deliveries', async (page) => {
        const gone = page.data.filter((row) => row.deleted_at).map((row) => row.id);
        await db.transaction('rw', db.deliveries, db.delivery_lines, async () => {
            await db.deliveries.bulkPut(page.data.filter((row) => !row.deleted_at));
            if (gone.length) {
                await db.deliveries.bulkDelete(gone);
                await db.delivery_lines.where('delivery_id').anyOf(gone).delete();
            }
        });
    });
}

/**
 * BL lines. The server rewrites a BL's lines all together with one
 * updated_at, so lines of a BL older than the ones received are a previous
 * version: they go. Lines of the same version on an earlier page stay.
 */
export async function syncDeliveryLines(db: UserDb, get: Getter): Promise<void> {
    await pullResumable<MirrorDeliveryLine>(db, get, 'deliveryLines', '/api/delivery-lines', async (page) => {
        const newest = new Map<number, number>();
        for (const line of page.data) newest.set(line.delivery_id, Math.max(newest.get(line.delivery_id) ?? 0, Date.parse(line.updated_at)));
        await db.transaction('rw', db.delivery_lines, async () => {
            await db.delivery_lines
                .where('delivery_id')
                .anyOf([...newest.keys()])
                .filter((line) => Date.parse(line.updated_at) < (newest.get(line.delivery_id) ?? 0))
                .delete();
            await db.delivery_lines.bulkPut(page.data);
        });
    });
}

/** Meta key set once the BL history is complete on the phone (ISO). */
export const HISTORY_SYNCED_AT = 'history.syncedAt';

/** Everything, in order; the caller handles errors (offline). The BL history comes last. */
export async function syncMirror(db: UserDb, get: Getter, now = Date.now()): Promise<void> {
    await syncPaytypes(db, get);
    await syncClients(db, get, now);
    await syncArticles(db, get);
    await syncOrders(db, get);
    await db.setMeta(SYNCED_AT, new Date(now).toISOString());
    await syncDeliveries(db, get);
    await syncDeliveryLines(db, get);
    await db.setMeta(HISTORY_SYNCED_AT, new Date(now).toISOString());
}
