import { describe, expect, it } from 'vitest';

import { markRefusal } from './markExported';

describe('markRefusal', () => {
    it('gives the server\'s reasons when an order was skipped, nothing otherwise', () => {
        expect(markRefusal({ exported: ['C-2026-0001'], skipped: [] })).toBeNull();
        expect(markRefusal({ exported: [], skipped: [{ number: 'C-2026-0002', reason: 'Evoliz a peut-être déjà le BL de cette commande : elle n’est pas marquée exportée.' }] }))
            .toBe('Evoliz a peut-être déjà le BL de cette commande : elle n’est pas marquée exportée.');
    });
});
