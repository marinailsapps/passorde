import { describe, expect, it } from 'vitest';

import { visibleRows } from './stockReport';

const rows = [
    { reference: 'ACTIVE', active: true },
    { reference: 'OLD VARIETY', active: false },
];

describe('visibleRows', () => {
    it('hides inactive articles by default, and shows them on request', () => {
        expect(visibleRows({ key: 'pommier', kind: 'stock', rows }, true).map((row) => row.reference)).toEqual(['ACTIVE']);
        expect(visibleRows({ key: 'pommier', kind: 'stock', rows }, false)).toHaveLength(2);
    });

    it('always lists everything in À classer and in the event tabs', () => {
        expect(visibleRows({ key: 'a_classer', kind: 'stock', rows }, true)).toHaveLength(2);
        expect(visibleRows({ key: 'broken', kind: 'broken', rows: [{ reference: 'X' }] }, true)).toHaveLength(1);
    });
});
