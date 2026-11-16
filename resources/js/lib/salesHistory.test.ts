import { describe, expect, it } from 'vitest';

import { historyEntries, recentFromHistory } from './salesHistory';
import type { DeliveryLineRow, DeliveryRow, OrderDetail } from '@/types';

const bl = (id: number, extra: Partial<DeliveryRow> = {}): DeliveryRow => ({
    id,
    document_number: `BL-2026000${id}`,
    client_id: 7,
    client_name: 'SARL FICTIF',
    order_uuid: null,
    order_number: null,
    date: '2026-09-02',
    invoiced: false,
    seller_id: null,
    seller: 'Evoliz',
    comment: null,
    total_ht: '320.00',
    total_vat: '17.60',
    total_ttc: '337.60',
    deleted_at: null,
    updated_at: '2026-10-01T10:00:00+00:00',
    ...extra,
});
const blLine = (id: number, deliveryId: number, position: number, extra: Partial<DeliveryLineRow> = {}): DeliveryLineRow => ({
    id,
    delivery_id: deliveryId,
    position,
    article_id: 6,
    reference: 'VELOURS TIGE POM',
    designation: 'VELOURS TIGE',
    quantity: '10.00',
    unit_price_ht: '32.00',
    vat_rate: '5.50',
    is_free: false,
    total_ht: '320.00',
    updated_at: '2026-10-01T10:00:00+00:00',
    ...extra,
});
const order = (uuid: string, extra: Partial<OrderDetail> & { deleted_at?: string | null } = {}) =>
    ({
        uuid,
        number: 'C-2026-0001',
        status: 'pending',
        created_at: '2026-10-19T23:30:00+00:00',
        sent_at: null,
        evoliz_delivery_number: null,
        author: { id: 2, name: 'Ysolde Dupont' },
        comment: null,
        lines: [{ position: 0, article_id: 6, reference: 'VELOURS TIGE POM', designation: 'VELOURS TIGE', quantity: '3.00', unit_price_ht: '32.00', vat_rate: '5.50', is_free: false, total_ht: '96.00', stock: null, catalog_unit_price_ht: '34.00' }],
        totals: { quantity: '3.00', ht: '96.00', vat: '5.28', ttc: '101.28', vat_by_rate: [] },
        deleted_at: null,
        ...extra,
    }) as OrderDetail & { deleted_at?: string | null };

describe('sales history on the phone', () => {
    it('counts an exported order as delivered, dated by its export, like the server', () => {
        const entries = historyEntries(
            [],
            [],
            [
                order('exported', { number: 'C-2026-0005', status: 'approved', exported_at: '2026-10-21T23:30:00+00:00' }),
                order('waiting', { number: 'C-2026-0004', status: 'approved', created_at: '2026-10-19T08:00:00+00:00', exported_at: null }),
                order('older-row', { number: 'C-2026-0003', status: 'approved', created_at: '2026-10-18T08:00:00+00:00' }),
            ],
        );

        expect(entries.map((entry) => [entry.date, entry.order_number, entry.status])).toEqual([
            ['2026-10-22', 'C-2026-0005', 'delivered'],
            ['2026-10-19', 'C-2026-0004', 'pending'],
            ['2026-10-18', 'C-2026-0003', 'pending'],
        ]);
    });

    it('merges BLs and orders not in Evoliz, newest first, like the server', () => {
        const entries = historyEntries(
            [bl(1, { order_uuid: 'sent', order_number: 'C-2026-0002', seller: 'Ysolde Dupont', invoiced: true }), bl(2, { date: '2024-03-05' })],
            [blLine(11, 1, 1, { quantity: '1.00', unit_price_ht: '0.00', is_free: true, total_ht: '0.00' }), blLine(10, 1, 0)],
            [order('pending'), order('sent', { status: 'sent' }), order('deleted', { deleted_at: '2026-10-20T10:00:00+00:00' })],
        );

        expect(entries.map((e) => [e.key, e.date, e.status, e.seller])).toEqual([
            ['order:pending', '2026-10-20', 'pending', 'Ysolde Dupont'],
            ['bl:1', '2026-09-02', 'invoiced', 'Ysolde Dupont'],
            ['bl:2', '2024-03-05', 'delivered', 'Evoliz'],
        ]);
        expect(entries[1].lines.map((l) => l.is_free)).toEqual([false, true]);
    });

    it('lists an order exported during a lapse once, as its BL, when the BL arrives, like the server', () => {
        const exported = order('exported', { number: 'C-2026-0005', status: 'approved', exported_at: '2026-10-20T09:00:00+00:00' });
        const entries = historyEntries([bl(3, { order_uuid: 'exported', order_number: 'C-2026-0005', date: '2026-10-20' })], [blLine(30, 3, 0)], [exported]);

        expect(entries.map((e) => [e.key, e.order_number, e.status])).toEqual([['bl:3', 'C-2026-0005', 'delivered']]);
    });

    it('offers the last 3 entries to copy with stock, catalogue price and availability', () => {
        const entries = historyEntries([bl(1)], [blLine(10, 1, 0), blLine(11, 1, 1, { article_id: null, reference: null, designation: 'Ligne libre', vat_rate: null })], []);
        const articles = new Map([[6, { is_active: true, is_stocked: true, stock_qty: '7.00', reserved_qty: '1.00', unit_price_ht: '34.00' }]]);

        const [recent] = recentFromHistory(entries, articles, []);

        expect(recent).toMatchObject({ uuid: 'bl:1', number: 'BL-20260001', status: 'sent', history_status: 'delivered', author: 'Evoliz', total_ttc: '337.60' });
        expect(recent.lines.map((l) => [l.position, l.available, l.catalog_unit_price_ht, l.vat_rate])).toEqual([
            [0, true, '34.00', '5.50'],
            [1, false, null, '0.00'],
        ]);
    });
});
