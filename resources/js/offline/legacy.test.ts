import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { deleteUserDb, type LocalOrder, type SessionRecord, type UserDb, userDb } from './db';
import { currentGrouping, readClient, readDraft } from './legacy';
import { HISTORY_SYNCED_AT } from './mirror';
import { runOutbox, type Transport } from './outbox';
import { buildLocalPage } from './pages';
import { searchLocalClients } from './search';

/*
 * A phone of an installation that renamed fields (the fictional tests/Fixtures/profiles/renamed, its maps copied
 * below, as a build with that profile puts them in the bundle) still holds what the older build stored: an unsent
 * outbox entry, its local order, mirror rows and a form draft, all under the old field names. None of it may be lost
 * or changed; the code reads it under the current names.
 */
vi.mock('../lib/brand', async (original) => {
    const actual = await original<typeof import('../lib/brand')>();
    const aliases = {
        client: { extra_field: 'member_code' },
        article: { category: 'family', variant: 'kind' },
        totals: { quantity: 'pieces' },
        groupings: { family: 'category', kind: 'variant' },
    };
    return { ...actual, brand: { ...actual.brand, aliases } };
});

let db: UserDb;
const session = { key: 'current', user: { id: 1, name: 'Ysolde', role: 'user' }, appVersion: '1', turnstileSiteKey: null, version: null } as unknown as SessionRecord;

beforeEach(() => {
    db = userDb(7000 + Math.floor(Math.random() * 1000));
});
afterEach(async () => {
    await deleteUserDb(db.userId);
});

/** The body an old build queued: a typed client and a correction, under the old name. */
const oldBody = {
    client: { uuid: '5d0c1f7e-2b8a-4c1e-9f3d-7a6b5c4d3e2f', company_name: 'EURL NOUVELLE', contact_name: '', address_line: '', postcode: '19770', town: 'MONTFICTIF', phone: '', email: '', member_code: 'K-1' },
    lines: [{ article_id: 6, quantity: 3, unit_price_ht: 32, is_free: false, position: 0 }, { article_id: 6, quantity: 1, unit_price_ht: 0, is_free: true, position: 1 }],
    paytype_id: null,
    comment: '',
    duplicated_from: null,
};

/** The local copy an old build kept of that order (old names: client.member_code, totals.pieces, the correction's key). */
function oldOrder(uuid: string): Record<string, unknown> {
    return {
        uuid,
        number: '',
        status: 'local',
        display_status: 'local',
        status_label: 'Brouillon local',
        created_at: '2026-11-14T08:00:00.000Z',
        updated_at: '2026-11-14T08:00:00.000Z',
        approved_at: null,
        approved_by: null,
        sent_at: null,
        exported_at: null,
        author: { id: db.userId, name: 'Ysolde' },
        client: { id: 0, uuid: oldBody.client.uuid, company_name: 'EURL NOUVELLE', contact_name: null, address_line: null, postcode: '19770', town: 'MONTFICTIF', phone: null, email: null, member_code: 'K-1', linked: false },
        client_change: { status: 'pending', changes: { phone: { from: '06', to: '07' }, member_code: { from: null, to: 'K-2' }, town: { from: 'A', to: 'B' } }, error: null, decided_by: null, applied_at: null },
        paytype: null,
        comment: null,
        lines: [],
        totals: { pieces: '4.00', ht: '96.00', vat: '5.28', ttc: '101.28', vat_by_rate: [] },
        rejected_reason: null,
        evoliz_delivery_number: null,
        can_edit: true,
        can_delete: true,
        evoliz_error: null,
        evoliz_warning: null,
        activity: null,
        can_approve: false,
        can_reject: false,
        can_retry: false,
        stock_conflicts: null,
        deleted_at: null,
        local_state: 'queued',
        local_error: null,
    };
}

describe('an old build\'s outbox entry', () => {
    it('is sent exactly as it was stored, and the order is not lost', async () => {
        const uuid = 'a0000000-0000-4000-8000-000000000001';
        await db.orders.put(oldOrder(uuid) as unknown as LocalOrder);
        await db.outbox.add({ uuid, method: 'PUT', path: `/api/orders/${uuid}`, body: oldBody, attempts: 0, lastError: null, createdAt: 1, nextAttemptAt: 0 });
        const stored = JSON.stringify((await db.outbox.toArray())[0].body);

        const sent: { method: string; path: string; body: string }[] = [];
        const transport: Transport = {
            async send(method, path, body) {
                sent.push({ method, path, body: JSON.stringify(body) });
                return { status: 201, body: { order: { ...oldOrder(uuid), number: 'C-2026-0040', status: 'pending', display_status: 'pending', status_label: 'En attente', local_state: undefined }, possible_duplicate: null } };
            },
        };

        expect(await runOutbox({ db, transport })).toEqual({ outcome: 'done', sent: 1, refused: 0 });
        expect(sent).toEqual([{ method: 'PUT', path: `/api/orders/${uuid}`, body: stored }]);
        expect(JSON.parse(stored)).toEqual(oldBody);
        expect(await db.outbox.count()).toBe(0);
        expect(await db.orders.get(uuid)).toMatchObject({ number: 'C-2026-0040', local_state: 'synced' });
    });

    it('waits in the outbox, untouched, while the phone is offline, and its order shows with the new names', async () => {
        const uuid = 'a0000000-0000-4000-8000-000000000002';
        await db.orders.put(oldOrder(uuid) as unknown as LocalOrder);
        await db.outbox.add({ uuid, method: 'PUT', path: `/api/orders/${uuid}`, body: oldBody, attempts: 0, lastError: null, createdAt: 1, nextAttemptAt: 0 });

        const offline: Transport = { send: () => Promise.reject(new TypeError('Failed to fetch')) };
        await runOutbox({ db, transport: offline });

        const [entry] = await db.outbox.toArray();
        expect(entry.body).toEqual(oldBody);

        const order = await db.orders.get(uuid);
        expect(order?.totals.quantity).toBe('4.00');
        expect(order?.client.extra_field).toBe('K-1');
        // The correction keeps its fields in their order, the old key replaced where it stood.
        expect(Object.keys(order?.client_change?.changes ?? {})).toEqual(['phone', 'extra_field', 'town']);

        const page = await buildLocalPage(new URL(`https://app.test/orders/${uuid}`), db, session);
        expect(page.component).toBe('Orders/Show');
        expect(page.props.order).toMatchObject({ uuid, totals: { quantity: '4.00' }, client: { extra_field: 'K-1' } });
    });
});

describe('an old build\'s mirror rows', () => {
    it('are read under the new names: the client search and the offline sales report find them', async () => {
        await db.clients.put({ key: 'id:7', id: 7, uuid: null, company_name: 'SARL FICTIF', contact_name: null, address_line: null, postcode: '18380', town: 'BOISJOLI', phone: null, email: null, member_code: 'MX4410', linked: true, is_active: true, updated_at: null } as never);
        await db.articles.put({ id: 6, reference: 'VISION', designation: '', unit_price_ht: '32.00', vat_rate: '5.50', is_stocked: true, stock_qty: '1.00', reserved_qty: '0.00', is_active: true, updated_at: null, family: 'POMMIER', kind: 'nu' } as never);
        await db.deliveries.put({ id: 1, document_number: 'BL-1', client_id: 7, client_name: 'SARL', order_uuid: null, order_number: null, date: '2026-10-05', invoiced: true, seller_id: 2, seller: 'Ysolde Dupont', comment: null, total_ht: '320.00', total_vat: '17.60', total_ttc: '337.60', deleted_at: null, updated_at: '' });
        await db.delivery_lines.put({ id: 1, delivery_id: 1, position: 0, article_id: 6, reference: 'VISION', designation: 'VISION', quantity: '10.00', unit_price_ht: '32.00', vat_rate: '5.50', is_free: false, total_ht: '320.00', updated_at: '' });
        await db.setMeta(HISTORY_SYNCED_AT, '2026-10-22T08:00:00Z');

        const clients = await db.clients.toArray();
        expect(searchLocalClients(clients, 'MX4410', true).map((client) => [client.id, client.extra_field])).toEqual([[7, 'MX4410']]);

        const catalog = { new_article: true, variants: [{ value: 'nu', label: 'NU', name: 'Racines nues', counts: true, own_tab: false }] };
        const page = await buildLocalPage(new URL('https://app.test/reports/sales?from=2026-10-01&to=2026-10-31'), db, { ...session, catalog });
        const report = page.props.report as { cards: { quantity: string }; groups: { category: { key: string }[]; variant: { key: string }[] } };
        expect(report.cards.quantity).toBe('10.00');
        expect(report.groups.category.map((row) => row.key)).toEqual(['POMMIER']);
        expect(report.groups.variant.map((row) => row.key)).toEqual(['nu']);
    });
});

describe('an old build\'s order form draft', () => {
    it('keeps the typed client field and the correction under the new names', () => {
        const draft = readDraft({
            step: 1,
            selection: { kind: 'existing', client: { id: 7, member_code: 'MX4410' }, changes: { member_code: 'MX4411', phone: '07' } },
        });

        expect(draft.selection.client).toMatchObject({ extra_field: 'MX4410' });
        expect(draft.selection.changes).toEqual({ extra_field: 'MX4411', phone: '07' });
        expect(readDraft({ step: 1, selection: { kind: 'new', client: { uuid: 'x', member_code: 'K-1' } } }).selection.client).toMatchObject({ extra_field: 'K-1' });
    });
});

describe('an installation without older names', () => {
    it('reads every row as it is', () => {
        const none = { client: {}, article: {}, totals: {} };
        expect(readClient({ id: 1, member_code: 'K-1' }, none)).toEqual({ id: 1, member_code: 'K-1' });
    });
});


describe('a sales report link saved under an older grouping name', () => {
    it('opens the current grouping, and leaves current and unknown names as they are', () => {
        expect(currentGrouping('family')).toBe('category');
        expect(currentGrouping('kind')).toBe('variant');
        expect(currentGrouping('category')).toBe('category');
        expect(currentGrouping('seller')).toBe('seller');
        expect(currentGrouping(null)).toBeNull();
        expect(currentGrouping('family', {})).toBe('family');
    });
});
