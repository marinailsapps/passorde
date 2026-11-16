import { describe, expect, it } from 'vitest';

import { computeTotals, lineHtCents } from './orderTotals';

describe('computeTotals', () => {
    it('totals a whole order like the server: free lines, a rate with no base, VAT half up per rate', () => {
        // The invented order of tests/Feature/Orders/OrderTotalsTest.php: 343.00 × 5.5 % = 18.865 → 18.87.
        const totals = computeTotals([
            { quantity: 3, unit_price_ht: '83.00', vat_rate: '5.50' },
            { quantity: 1, unit_price_ht: 0, vat_rate: '5.50' },
            { quantity: 1, unit_price_ht: '94.00', vat_rate: '5.50' },
            { quantity: 2, unit_price_ht: 0, vat_rate: '5.50' },
            { quantity: 2, unit_price_ht: 0, vat_rate: '20.00' },
        ]);

        expect(totals.quantity).toBe(9);
        expect(totals.htCents).toBe(34300);
        expect(totals.vatCents).toBe(1887);
        expect(totals.ttcCents).toBe(36187);
        expect(totals.byRate).toEqual([
            { rate: 5.5, baseCents: 34300, vatCents: 1887 },
            { rate: 20, baseCents: 0, vatCents: 0 },
        ]);
    });

    it('rounds VAT once per rate, not per line', () => {
        const totals = computeTotals([
            { quantity: 1, unit_price_ht: '0.10', vat_rate: 5.5 },
            { quantity: 1, unit_price_ht: '0.10', vat_rate: 5.5 },
        ]);

        expect(totals.vatCents).toBe(1);
    });

    it('keeps separate rates apart', () => {
        const totals = computeTotals([
            { quantity: 3, unit_price_ht: '33.33', vat_rate: 5.5 },
            { quantity: 1, unit_price_ht: '15.00', vat_rate: 20 },
        ]);

        expect(totals.byRate).toEqual([
            { rate: 5.5, baseCents: 9999, vatCents: 550 },
            { rate: 20, baseCents: 1500, vatCents: 300 },
        ]);
        expect(totals.ttcCents).toBe(9999 + 1500 + 550 + 300);
    });

    it('rounds half up like the server', () => {
        expect(computeTotals([{ quantity: 1, unit_price_ht: '0.10', vat_rate: 5 }]).vatCents).toBe(1);
        expect(lineHtCents('2.5', '19.99')).toBe(4998);
        expect(lineHtCents('0.33', '0.33')).toBe(11);
    });

    it('treats an empty or invalid price as zero', () => {
        expect(computeTotals([{ quantity: 2, unit_price_ht: '', vat_rate: 5.5 }]).htCents).toBe(0);
    });
});
