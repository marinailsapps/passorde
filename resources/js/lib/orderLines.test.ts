import { describe, expect, it } from 'vitest';

import { createLineEditor, groupsFromLines } from './orderLines';
import type { ArticleHit, OrderLineData } from '@/types';

const article = (id: number, price = '32.00', vat = '5.50'): ArticleHit => ({
    id,
    reference: `REF${id}`,
    designation: `ARTICLE ${id}`,
    unit_price_ht: price,
    vat_rate: vat,
    is_stocked: true,
    stock_qty: '10.00',
    reserved_qty: '0.00',
});

describe('line editor', () => {
    it('adds articles and increments an article already in the order', () => {
        const editor = createLineEditor();
        editor.addArticle(article(1));
        editor.addArticle(article(2));
        editor.addArticle(article(1));

        expect(editor.state.groups.map((g) => [g.article.id, g.quantity])).toEqual([
            [1, 2],
            [2, 1],
        ]);
    });

    it('adds and increments the free unit of an article', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(1), 10);
        editor.addFree(group.key);
        editor.addFree(group.key);

        expect(editor.state.groups[0].freeQuantity).toBe(2);
        expect(editor.toApiLines()).toEqual([
            { article_id: 1, quantity: 10, unit_price_ht: 32, is_free: false, position: 0 },
            { article_id: 1, quantity: 2, unit_price_ht: 0, is_free: true, position: 1 },
        ]);
    });

    it('removes a line together with its free units', () => {
        const editor = createLineEditor();
        const first = editor.addArticle(article(1));
        editor.addFree(first.key);
        editor.addArticle(article(2));
        editor.remove(first.key);

        expect(editor.toApiLines()).toEqual([{ article_id: 2, quantity: 1, unit_price_ht: 32, is_free: false, position: 0 }]);
    });

    it('puts a new article from the search first, and leaves a repeated one in place', () => {
        const editor = createLineEditor();
        editor.addArticle(article(1), 1, undefined, true);
        editor.addArticle(article(2), 1, undefined, true);
        editor.addArticle(article(1), 1, undefined, true);

        expect(editor.state.groups.map((group) => [group.article.id, group.quantity])).toEqual([[2, 1], [1, 2]]);
        expect(editor.toApiLines().map((line) => line.article_id)).toEqual([2, 1]);
    });

    it('reorders lines, keeping free units right after their article', () => {
        const editor = createLineEditor();
        const first = editor.addArticle(article(1));
        editor.addFree(first.key);
        const second = editor.addArticle(article(2));
        editor.move(second.key, -1);
        editor.move(second.key, -1); // already first: no-op

        expect(editor.toApiLines().map((l) => [l.article_id, l.is_free, l.position])).toEqual([
            [2, false, 0],
            [1, false, 1],
            [1, true, 2],
        ]);
    });

    it('flags a 0 € catalogue price until the user types one', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(3, '0.00'));
        expect(group.priceEdited).toBe(false);

        editor.setPrice(group.key, '12.50');
        expect(editor.state.groups[0]).toMatchObject({ unitPrice: '12.50', priceEdited: true });
    });

    it('appends the lines of a previous order, free units nested', () => {
        const lines: OrderLineData[] = [
            { position: 0, article_id: 1, reference: 'A', designation: 'A', quantity: '10.00', unit_price_ht: '30.00', vat_rate: '5.50', is_free: false, total_ht: '300.00', stock: null, catalog_unit_price_ht: null },
            { position: 1, article_id: 1, reference: 'A', designation: 'A', quantity: '1.00', unit_price_ht: '0.00', vat_rate: '5.50', is_free: true, total_ht: '0.00', stock: null, catalog_unit_price_ht: null },
            { position: 2, article_id: 2, reference: 'B', designation: 'B', quantity: '6.00', unit_price_ht: '52.00', vat_rate: '5.50', is_free: false, total_ht: '312.00', stock: null, catalog_unit_price_ht: null },
        ];
        const editor = createLineEditor();
        editor.addArticle(article(2), 1);
        editor.appendLines(lines);

        expect(editor.state.groups.map((g) => [g.article.id, g.quantity, g.freeQuantity, g.unitPrice])).toEqual([
            [2, 7, 0, '32.00'],
            [1, 10, 1, '30.00'],
        ]);
    });

    it('keeps a copied price and remembers the catalogue price', () => {
        const editor = createLineEditor();
        editor.appendLines([
            { position: 0, article_id: 1, reference: 'A', designation: 'A', quantity: '5.00', unit_price_ht: '50.00', vat_rate: '5.50', is_free: false, total_ht: '250.00', stock: null, catalog_unit_price_ht: '55.00' },
        ]);

        expect(editor.state.groups[0]).toMatchObject({ unitPrice: '50.00', priceEdited: true });
        expect(editor.state.groups[0].article.unit_price_ht).toBe('55.00');
    });

    it('computes totals across paid and free lines', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(1, '83.00'), 3);
        editor.addFree(group.key);
        editor.addArticle(article(2, '94.00'), 1);

        expect(editor.totals.value).toMatchObject({ quantity: 5, htCents: 34300, vatCents: 1887, ttcCents: 36187 });
    });

    it('never goes below zero', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(1));
        editor.setQuantity(group.key, -3);
        editor.setFreeQuantity(group.key, -1);

        expect(editor.state.groups[0]).toMatchObject({ quantity: 0, freeQuantity: 0 });
    });
});

describe('line comments', () => {
    const article = (id: number) => ({ id, reference: `R${id}`, designation: `D${id}`, unit_price_ht: '10.00', vat_rate: '5.50', is_stocked: true, stock_qty: '5', reserved_qty: '0' });

    it('sends the comments of the paid line and the free units, trimmed, and nothing when blank', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(1), 2);
        const other = editor.addArticle(article(2), 1);
        editor.addFree(group.key);
        editor.setNote(group.key, '  veste  ');
        editor.setNote(group.key, 'remplacement', true);
        editor.setNote(other.key, '   ');

        const lines = editor.toApiLines();
        expect(lines.map((line) => line.note)).toEqual(['veste', 'remplacement', undefined]);
    });

    it('drops the free units comment with the free units', () => {
        const editor = createLineEditor();
        const group = editor.addArticle(article(1), 2);
        editor.setNote(group.key, 'remplacement', true);

        expect(editor.toApiLines().map((line) => line.note)).toEqual([undefined]);
    });

    it('brings the comments back when an order is edited, and copes with lines that have none', () => {
        const line = (overrides: object) => ({
            position: 0, article_id: 1, reference: 'R1', designation: 'D1', quantity: '2.00', unit_price_ht: '10.00', vat_rate: '5.50', is_free: false, total_ht: '20.00', stock: null, catalog_unit_price_ht: null, ...overrides,
        });
        const [group] = groupsFromLines([line({ note: 'veste' }), line({ position: 1, quantity: '1.00', is_free: true, note: 'offerte tombola' })]);
        expect(group).toMatchObject({ note: 'veste', freeNote: 'offerte tombola' });

        const [plain] = groupsFromLines([line({})]);
        expect(plain.note).toBeUndefined();
    });
});
