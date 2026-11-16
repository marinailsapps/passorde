import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { deleteUserDb, type UserDb, userDb } from './db';
import { ARTICLE_FIELDS, EPOCH, type Getter, syncArticles, syncClients, syncDeliveries, syncDeliveryLines, syncOrders } from './mirror';

let nextUser = 3000;
let db: UserDb;

beforeEach(() => {
    db = userDb(nextUser++);
});
afterEach(async () => {
    await deleteUserDb(db.userId);
});

type Query = Record<string, string | undefined>;

/** A fake API: records each query and answers with the next page. */
function api(pages: Record<string, unknown[]>): Getter & { queries: { path: string; query: Query }[] } {
    const queries: { path: string; query: Query }[] = [];
    const get = (async (path: string, query: Query) => {
        queries.push({ path, query });
        const next = pages[path]?.shift();
        if (next === undefined) throw new Error(`no page for ${path}`);
        return next;
    }) as Getter & { queries: typeof queries };
    get.queries = queries;
    return get;
}

const page = (data: unknown[], until: string, next: string | null = null, extra: Record<string, unknown> = {}) => ({ data, meta: { per_page: 200, next_cursor: next, until }, ...extra });
const client = (id: number, extra: Record<string, unknown> = {}) => ({ id, uuid: null, company_name: `CLIENT ${id}`, is_active: true, linked: true, updated_at: null, ...extra });

describe('mirror', () => {
    it('pages a first full load, then asks for changes since the last until minus a minute', async () => {
        const get = api({
            '/api/clients': [page([client(1)], '2026-10-01T10:00:00+00:00', 'c2'), page([client(2)], '2026-10-01T10:00:00+00:00'), page([client(2, { is_active: false })], '2026-10-01T10:15:00+00:00')],
        });
        const now = Date.parse('2026-10-01T10:00:00Z');

        await syncClients(db, get, now);
        expect(get.queries.map((q) => q.query)).toEqual([
            { updated_since: EPOCH, until: undefined, cursor: undefined },
            { updated_since: EPOCH, until: '2026-10-01T10:00:00+00:00', cursor: 'c2' },
        ]);
        expect(await db.clients.count()).toBe(2);

        await syncClients(db, get, now + 15 * 60_000);
        expect(get.queries[2].query.updated_since).toBe('2026-10-01T09:59:00.000Z');
        expect((await db.clients.get('id:2'))?.is_active).toBe(false);
    });

    it('reloads the whole directory once a day and drops clients the server no longer has', async () => {
        const day = 24 * 3600_000;
        const get = api({
            '/api/clients': [page([client(1), client(2)], '2026-10-01T10:00:00+00:00'), page([client(1)], '2026-10-02T10:00:00+00:00')],
        });
        await db.clients.put({ ...client(0, { uuid: 'typed', linked: false }), key: 'uuid:typed', pending: true } as never);

        await syncClients(db, get, 0);
        await syncClients(db, get, day);

        expect(get.queries[1].query.updated_since).toBe(EPOCH);
        expect((await db.clients.toCollection().primaryKeys()).sort()).toEqual(['id:1', 'uuid:typed']);
    });

    it('replaces a client typed on the phone once the server has it', async () => {
        await db.clients.put({ ...client(0, { uuid: 'typed', linked: false }), key: 'uuid:typed', pending: true } as never);

        await syncClients(db, api({ '/api/clients': [page([client(55, { uuid: 'typed', linked: false })], '2026-10-01T10:00:00+00:00')] }), 0);

        expect(await db.clients.get('uuid:typed')).toBeUndefined();
        expect((await db.clients.get('id:55'))?.uuid).toBe('typed');
    });

    it('stores stock at sync time and refreshes every reservation', async () => {
        await db.articles.put({ id: 9, reference: 'OLD', designation: '', unit_price_ht: '1.00', vat_rate: '5.50', is_stocked: true, stock_qty: '2.00', reserved_qty: '5.00', is_active: true, updated_at: null });
        const article = { id: 6, reference: 'ASTRA MT POM', designation: '', unit_price_ht: '53.00', vat_rate: '5.50', is_stocked: true, stock_qty: '7.00', reserved_qty: '3.00', is_active: true, updated_at: null };

        await syncArticles(db, api({ '/api/articles': [page([article], '2026-10-01T10:00:00+00:00', null, { reservations: { 6: '3.00' } })] }));

        expect(await db.articles.get(6)).toMatchObject({ stock_qty: '7.00', reserved_qty: '3.00' });
        // Article 9 did not change, but its reservations are gone.
        expect((await db.articles.get(9))?.reserved_qty).toBe('0.00');
    });

    it('mirrors the user\'s orders, removes deleted ones and leaves queued changes alone', async () => {
        await db.orders.put({ uuid: 'gone', number: 'C-2026-0001' } as never);
        await db.orders.put({ uuid: 'mine', number: 'C-2026-0002', comment: 'edited offline', local_state: 'queued' } as never);
        await db.outbox.add({ uuid: 'mine', method: 'PUT', path: '/api/orders/mine', body: {}, attempts: 0, lastError: null, createdAt: 0, nextAttemptAt: 0 });

        await syncOrders(
            db,
            api({
                '/api/orders': [
                    page(
                        [
                            { uuid: 'gone', number: 'C-2026-0001', deleted_at: '2026-10-01T09:00:00+00:00' },
                            { uuid: 'mine', number: 'C-2026-0002', comment: 'server', deleted_at: null },
                            { uuid: 'new', number: 'C-2026-0003', status: 'approved', deleted_at: null },
                        ],
                        '2026-10-01T10:00:00+00:00',
                    ),
                ],
            }),
        );

        expect(await db.orders.get('gone')).toBeUndefined();
        expect((await db.orders.get('mine'))?.comment).toBe('edited offline');
        expect(await db.orders.get('new')).toMatchObject({ status: 'approved', local_state: 'synced' });
    });

    it('reloads every article once when the rows gain fields (category and type for the sales report)', async () => {
        const article = { id: 6, reference: 'ASTRA MT POM', designation: '', unit_price_ht: '53.00', vat_rate: '5.50', is_stocked: true, stock_qty: '7.00', reserved_qty: '0.00', is_active: true, updated_at: null };
        await db.setMeta('articles.until', '2026-10-01T10:00:00+00:00');
        const get = api({
            '/api/articles': [page([{ ...article, category: 'POMMIER', variant: 'motte' }], '2026-10-22T10:00:00+00:00'), page([], '2026-10-22T10:15:00+00:00')],
        });

        await syncArticles(db, get);
        await syncArticles(db, get);

        expect(get.queries.map((q) => q.query.updated_since)).toEqual([EPOCH, '2026-10-22T09:59:00.000Z']);
        expect(await db.articles.get(6)).toMatchObject({ category: 'POMMIER', variant: 'motte' });
        expect(await db.getMeta('articles.fields')).toBe(ARTICLE_FIELDS);
    });
});

const delivery = (id: number, extra: Record<string, unknown> = {}) => ({
    id,
    document_number: `BL-${id}`,
    client_id: 7,
    client_name: 'SARL FICTIF',
    order_uuid: null,
    order_number: null,
    date: '2026-09-02',
    invoiced: false,
    seller_id: null,
    seller: 'Evoliz',
    comment: null,
    total_ht: '30.00',
    total_vat: '1.65',
    total_ttc: '31.65',
    deleted_at: null,
    updated_at: '2026-10-01T10:00:00+00:00',
    ...extra,
});
const line = (id: number, deliveryId: number, updatedAt: string, extra: Record<string, unknown> = {}) => ({
    id,
    delivery_id: deliveryId,
    position: 0,
    article_id: 6,
    reference: 'VELOURS TIGE POM',
    designation: 'VISION',
    quantity: '1.00',
    unit_price_ht: '30.00',
    vat_rate: '5.50',
    is_free: false,
    total_ht: '30.00',
    updated_at: updatedAt,
    ...extra,
});

describe('BL history mirror', () => {
    it('keeps every BL, drops a deleted one with its lines', async () => {
        await db.delivery_lines.put(line(1, 2, '2026-09-01T10:00:00+00:00'));

        await syncDeliveries(db, api({ '/api/deliveries': [page([delivery(1), delivery(2, { deleted_at: '2026-10-01T09:00:00+00:00' })], '2026-10-01T10:00:00+00:00')] }));

        expect(await db.deliveries.toCollection().primaryKeys()).toEqual([1]);
        expect(await db.delivery_lines.count()).toBe(0);
    });

    it('resumes a first load cut short where it stopped', async () => {
        const until = '2026-10-01T10:00:00+00:00';
        const failing = api({ '/api/deliveries': [page([delivery(1)], until, 'c2')] });

        await expect(syncDeliveries(db, failing)).rejects.toThrow('no page');

        const resumed = api({ '/api/deliveries': [page([delivery(2)], until)] });
        await syncDeliveries(db, resumed);

        expect(resumed.queries[0].query).toEqual({ updated_since: EPOCH, until, cursor: 'c2' });
        expect(await db.deliveries.count()).toBe(2);
        expect(await db.getMeta('deliveries.progress')).toBeUndefined();
        expect(await db.getMeta('deliveries.until')).toBe(until);
    });

    it('replaces the lines of a BL rewritten on the server, across pages, and leaves the others', async () => {
        const old = '2026-09-01T10:00:00+00:00';
        const rewritten = '2026-10-01T09:30:00+00:00';
        await db.delivery_lines.bulkPut([line(1, 10, old), line(2, 10, old, { position: 1 }), line(3, 11, old)]);

        await syncDeliveryLines(
            db,
            api({
                '/api/delivery-lines': [
                    page([line(20, 10, rewritten, { quantity: '12.00' })], '2026-10-01T10:00:00+00:00', 'c2'),
                    page([line(21, 10, rewritten, { position: 1, is_free: true })], '2026-10-01T10:00:00+00:00'),
                ],
            }),
        );

        expect((await db.delivery_lines.where('delivery_id').equals(10).toArray()).map((l) => [l.id, l.quantity])).toEqual([
            [20, '12.00'],
            [21, '1.00'],
        ]);
        expect(await db.delivery_lines.get(3)).toBeDefined();
    });
});
