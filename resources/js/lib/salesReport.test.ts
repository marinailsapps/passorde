import { describe, expect, it } from 'vitest';

import cases from '../../../tests/Fixtures/sales-report-cases.json';
import { aggregateSales, salesPresets, type SalesInput, type SalesResult } from './salesReport';

// Pinned together with App\Domain\Reports\SalesReport::aggregate() (tests/Unit/SalesReportTest.php).
describe('sales report aggregation', () => {
    it.each(cases.map((c) => [c.name, c.input, c.expected] as const))('%s', (_name, input, expected) => {
        expect(aggregateSales(input as unknown as SalesInput)).toEqual(expected as unknown as SalesResult);
    });

    it('offers the same quick periods as the server', () => {
        expect(salesPresets(new Date('2026-10-22T10:00:00+02:00'))).toEqual([
            { key: 'this_week', from: '2026-10-19', to: '2026-10-22' },
            { key: 'last_week', from: '2026-10-12', to: '2026-10-18' },
            { key: 'this_month', from: '2026-10-01', to: '2026-10-22' },
            { key: 'this_quarter', from: '2026-10-01', to: '2026-10-22' },
            { key: 'this_year', from: '2026-01-01', to: '2026-10-22' },
        ]);
    });
});
