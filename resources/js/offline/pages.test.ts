import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { deleteUserDb, type SessionRecord, type UserDb, userDb } from './db';
import { HISTORY_SYNCED_AT } from './mirror';
import { buildLocalPage, matchRoute, salesFilters } from './pages';

let db: UserDb;
const session = { key: 'current', user: { id: 1, name: 'Ysolde', role: 'user' }, appVersion: '1', turnstileSiteKey: null, version: null } as unknown as SessionRecord;

beforeEach(() => {
    db = userDb(5000 + Math.floor(Math.random() * 1000));
});
afterEach(async () => {
    await deleteUserDb(db.userId);
});

describe('offline shared props', () => {
    const reports = new URL('https://app.test/reports');

    it('keeps the mode of the last online page', async () => {
        const page = await buildLocalPage(reports, db, { ...session, syncProvider: 'file' });
        expect(page.props).toMatchObject({ offline: true, syncProvider: 'file' });
    });

    it('keeps the optional client field of the last online page; an older session keeps it shown and searched', async () => {
        const field = { label: 'N° adhérent', searchable: true };
        expect((await buildLocalPage(reports, db, { ...session, clientExtraField: field })).props.clientExtraField).toEqual(field);
        expect((await buildLocalPage(reports, db, { ...session, clientExtraField: null })).props.clientExtraField).toBeNull();
        expect((await buildLocalPage(reports, db, session)).props.clientExtraField).toEqual({ label: 'Champ client', searchable: true });
    });

    it('keeps the wording and the catalogue of the last online page; an older session keeps lang/fr.json and the button', async () => {
        const wording = { words: { unit: 'plant', units: 'plants', free: 'plant offert', category: 'essence', categories: 'essences', item: 'Nouvelle variété' }, texts: {} };
        const catalog = { new_article: false, variants: [{ value: 'racine', label: 'Racines', name: 'Racines nues', counts: true }] };
        const page = await buildLocalPage(reports, db, { ...session, wording, catalog });
        expect(page.props.wording).toEqual(wording);
        expect(page.props.catalog).toEqual(catalog);

        const older = await buildLocalPage(reports, db, session);
        expect(older.props.wording).toBeNull();
        expect(older.props.catalog).toEqual({ new_article: true, variants: [] });
    });

    it('stays in Evoliz mode on a phone whose session is older than file mode', async () => {
        const page = await buildLocalPage(reports, db, session);
        expect(page.props.syncProvider).toBe('evoliz');
    });
});

describe('offline sales report', () => {
    it('shows a notice instead of zeros in file mode', async () => {
        const page = await buildLocalPage(new URL('https://app.test/reports/sales'), db, { ...session, syncProvider: 'file' });

        expect(page.component).toBe('Offline/Unavailable');
        expect(page.props.reason).toBe('sales');
    });

    it('reads its filters from the URL with the server defaults', () => {
        const today = new Date('2026-10-22T10:00:00+02:00');
        expect(salesFilters(new URL('https://app.test/reports/sales'), today)).toEqual({ from: '2026-10-01', to: '2026-10-22', delivered: true, invoiced: true, seller_id: null });
        expect(salesFilters(new URL('https://app.test/reports/sales?from=2026-12-31&to=2026-01-01&delivered=0&invoiced=1&seller=4'), today)).toEqual({
            from: '2026-01-01',
            to: '2026-12-31',
            delivered: false,
            invoiced: true,
            seller_id: 4,
        });
        expect(matchRoute(new URL('https://app.test/reports/sales?group=client'))).toEqual({ name: 'sales' });
    });

    it('computes the page from the phone\'s copy of the BLs', async () => {
        await db.deliveries.bulkPut([
            { id: 1, document_number: 'BL-1', client_id: 7, client_name: 'SARL', order_uuid: null, order_number: null, date: '2026-10-05', invoiced: true, seller_id: 2, seller: 'Ysolde Dupont', comment: null, total_ht: '320.00', total_vat: '17.60', total_ttc: '337.60', deleted_at: null, updated_at: '' },
            { id: 2, document_number: 'BL-2', client_id: 7, client_name: 'SARL', order_uuid: null, order_number: null, date: '2025-10-05', invoiced: true, seller_id: null, seller: 'Evoliz', comment: null, total_ht: '10.00', total_vat: '0.55', total_ttc: '10.55', deleted_at: null, updated_at: '' },
        ]);
        await db.delivery_lines.put({ id: 1, delivery_id: 1, position: 0, article_id: 6, reference: 'VISION', designation: 'VISION', quantity: '10.00', unit_price_ht: '32.00', vat_rate: '5.50', is_free: false, total_ht: '320.00', updated_at: '' });
        await db.articles.put({ id: 6, reference: 'VISION', designation: '', unit_price_ht: '32.00', vat_rate: '5.50', is_stocked: true, stock_qty: '1.00', reserved_qty: '0.00', is_active: true, updated_at: null, category: 'POMMIER', variant: 'nu' });
        await db.clients.put({ key: 'id:7', id: 7, uuid: null, company_name: 'SARL FICTIF', postcode: '59380', is_active: true, updated_at: null } as never);
        await db.setMeta(HISTORY_SYNCED_AT, '2026-10-22T08:00:00Z');

        const catalog = { new_article: true, variants: [{ value: 'nu', label: 'NU', name: 'Racines nues', counts: true, own_tab: false }] };
        const page = await buildLocalPage(new URL('https://app.test/reports/sales?from=2026-10-01&to=2026-10-31'), db, { ...session, catalog });

        expect(page.component).toBe('Reports/Sales');
        expect(page.props).toMatchObject({
            offline: true,
            historyComplete: true,
            sellers: [{ id: 2, name: 'Ysolde Dupont' }],
            report: { cards: { count: 1, quantity: '10.00', ht: '320.00', ttc: '337.60' } },
        });
        expect((page.props.report as { groups: { department: { key: string }[] } }).groups.department[0].key).toBe('59');
    });

    it('shows the notice, never figures, for a session older than the catalogue settings', async () => {
        const page = await buildLocalPage(new URL('https://app.test/reports/sales'), db, session);

        expect(page.component).toBe('Offline/Unavailable');
        expect(page.props.reason).toBe('sales');
    });

    it('counts and groups the variants as the session\'s catalogue says', async () => {
        await db.deliveries.put({ id: 3, document_number: 'BL-3', client_id: null, client_name: 'JARDIN', order_uuid: null, order_number: null, date: '2026-10-06', invoiced: true, seller_id: null, seller: 'Evoliz', comment: null, total_ht: '50.00', total_vat: '10.00', total_ttc: '60.00', deleted_at: null, updated_at: '' });
        await db.delivery_lines.bulkPut([
            { id: 3, delivery_id: 3, position: 0, article_id: 8, reference: 'GALA', designation: 'GALA', quantity: '3.00', unit_price_ht: '10.00', vat_rate: '20.00', is_free: false, total_ht: '30.00', updated_at: '' },
            { id: 4, delivery_id: 3, position: 1, article_id: 9, reference: 'SECATEUR', designation: 'SECATEUR', quantity: '1.00', unit_price_ht: '20.00', vat_rate: '20.00', is_free: false, total_ht: '20.00', updated_at: '' },
        ]);
        await db.articles.bulkPut([
            { id: 8, reference: 'GALA', designation: '', unit_price_ht: '10.00', vat_rate: '20.00', is_stocked: true, stock_qty: '1.00', reserved_qty: '0.00', is_active: true, updated_at: null, category: 'POMMIER', variant: 'racine' },
            { id: 9, reference: 'SECATEUR', designation: '', unit_price_ht: '20.00', vat_rate: '20.00', is_stocked: true, stock_qty: '1.00', reserved_qty: '0.00', is_active: true, updated_at: null, category: null, variant: 'outil' },
        ]);
        const catalog = { new_article: false, variants: [
            { value: 'racine', label: 'Racines nues', name: 'Racines nues', counts: true, own_tab: false },
            { value: 'outil', label: 'Outil', name: 'Outil', counts: false, own_tab: true },
        ] };

        const page = await buildLocalPage(new URL('https://app.test/reports/sales?from=2026-10-06&to=2026-10-06'), db, { ...session, catalog });
        const report = page.props.report as { cards: { quantity: string }; groups: { category: { key: string }[]; variant: { key: string; label: string }[] } };

        expect(report.cards.quantity).toBe('3.00');
        expect(report.groups.category.map((row) => row.key)).toEqual(['POMMIER', '~material']);
        expect(report.groups.variant.map((row) => row.label)).toEqual(['Racines nues', 'Outil']);
    });
});
