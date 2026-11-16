import { describe, expect, it } from 'vitest';

import { corrected, correctionDiff, correctionErrors, correctionForm, correctionOf, hasCorrection, isFrenchPhone, pendingChange } from './clientCorrection';
import { localOrderFromDraft } from '@/offline/orders';
import type { ClientData } from '@/types';

const client: ClientData = {
    id: 12,
    uuid: null,
    company_name: 'EXEMPLE AMBROISE',
    contact_name: 'Ambroise Exemple',
    address_line: '1 rue Exemple',
    postcode: '26380',
    town: 'BOISJOLI',
    phone: '09 70 00 00 00',
    mobile: '06 00 00 00 00',
    email: 'jp@example.test',
    extra_field: null,
    linked: true,
};

describe('correctionDiff', () => {
    it('keeps only what differs, trimmed, with blanks as null and the email in lower case', () => {
        const form = correctionForm(client);
        form.address_line = ' 2 route du Lac ';
        form.phone = '';
        form.email = 'JP@Example.test';
        form.extra_field = 'R-7Q3M';

        expect(correctionDiff(client, form)).toEqual({ address_line: '2 route du Lac', phone: null, extra_field: 'R-7Q3M' });
    });

    it('is empty when the form is back to the client', () => {
        expect(hasCorrection(correctionDiff(client, correctionForm(client)))).toBe(false);
    });

    it('treats a phone copy without mobile as an empty mobile', () => {
        const older: ClientData = { ...client };
        delete older.mobile;
        expect(correctionDiff(older, correctionForm(older))).toEqual({});
    });
});

describe('the form and the shown client', () => {
    it('starts from the correction made so far', () => {
        const form = correctionForm(client, { town: 'LAMBERTOU', phone: null });
        expect(form.town).toBe('LAMBERTOU');
        expect(form.phone).toBe('');
        expect(form.postcode).toBe('26380');
    });

    it('shows the client with the correction applied', () => {
        expect(corrected(client, { town: 'LAMBERTOU' }).town).toBe('LAMBERTOU');
        expect(corrected(client, undefined)).toBe(client);
    });

    it('reads a pending correction back from the order, and ignores a settled one', () => {
        const change = pendingChange(client, { town: 'LAMBERTOU', phone: null });
        expect(change.changes).toEqual({ town: { from: 'BOISJOLI', to: 'LAMBERTOU' }, phone: { from: '09 70 00 00 00', to: null } });
        expect(correctionOf(change)).toEqual({ town: 'LAMBERTOU', phone: null });
        expect(correctionOf({ ...change, status: 'applied' })).toBeUndefined();
    });

    it('reads the correctable fields only: the answer carries old names beside them', () => {
        const change = pendingChange(client, { extra_field: 'K-2' });
        const answered = { ...change, changes: { ...change.changes, member_code: { from: null, to: 'K-2' } } } as typeof change;
        expect(correctionOf(answered)).toEqual({ extra_field: 'K-2' });
    });
});

describe('correctionErrors (same rules as the server)', () => {
    const errorsFor = (changes: Partial<Record<string, string>>) => correctionErrors(client, { ...correctionForm(client), ...changes });

    it('accepts the client as it is', () => {
        expect(errorsFor({})).toEqual({});
    });

    it('checks postcode, town, phones and email', () => {
        expect(Object.keys(errorsFor({ postcode: '1538', town: ' ', phone: 'pas un numéro', mobile: '09 70 00 00 00', email: 'pas-un-email' }))).toEqual([
            'postcode',
            'town',
            'phone',
            'mobile',
            'email',
        ]);
    });

    it('lets the contact and the email be replaced, not removed', () => {
        expect(Object.keys(errorsFor({ contact_name: '', email: '' }))).toEqual(['contact_name', 'email']);
        expect(correctionErrors({ ...client, email: null }, { ...correctionForm(client), email: '' })).toEqual({});
    });
});

describe('isFrenchPhone', () => {
    it.each([
        ['09 70 12 34 56', false, true],
        ['+33 9 70 12 34 56', false, true],
        ['06.11.22.33.44', true, true],
        ['+33612345678', true, true],
        ['09 70 12 34 56', true, false],
        ['612345678', true, false],
        ['0970', false, false],
        ['pas un numéro', false, false],
    ])('%s (mobile: %s) → %s', (value, mobile, valid) => {
        expect(isFrenchPhone(value, mobile)).toBe(valid);
    });
});

describe('localOrderFromDraft', () => {
    it('carries the correction as a pending change, the client itself unchanged', () => {
        const order = localOrderFromDraft({
            uuid: 'a5b4c3d2-0000-4000-8000-000000000001',
            previous: null,
            selection: { kind: 'existing', client, changes: { town: 'LAMBERTOU' } },
            groups: [],
            lines: [],
            paytype: null,
            comment: '',
            author: { id: 1, name: 'Ysolde', login: 'ysolde', role: 'user' } as never,
        });

        expect(order.client.town).toBe('BOISJOLI');
        expect(order.client_change).toEqual({ status: 'pending', changes: { town: { from: 'BOISJOLI', to: 'LAMBERTOU' } }, error: null, decided_by: null, applied_at: null });
    });
});
