import { clientExtraLabel } from '@/lib/clientExtraField';
import { t } from '@/lib/i18n';
import type { ClientChangeView, ClientData } from '@/types';

/**
 * A sales person's correction of an existing client's details, sent with the
 * order as `client_changes` (docs/API.md) and applied to Evoliz when the
 * operator approves it. Same fields and checks as app/Domain/Clients/ClientChanges.php
 * and app/Rules/FrenchPhone.php.
 */
export const CORRECTABLE = ['contact_name', 'address_line', 'postcode', 'town', 'phone', 'mobile', 'email', 'extra_field'] as const;

export type ClientField = (typeof CORRECTABLE)[number];

/** The corrected fields only, with their new value (null clears it). */
export type ClientCorrection = Partial<Record<ClientField, string | null>>;

export type CorrectionForm = Record<ClientField, string>;

const normalise = (value: string | null | undefined): string | null => {
    const trimmed = (value ?? '').trim();
    return trimmed === '' ? null : trimmed;
};

const current = (client: ClientData, field: ClientField): string | null => normalise(client[field] ?? null);

export function fieldLabel(field: ClientField): string {
    return field === 'extra_field' ? clientExtraLabel() : t(`client_field.${field}`);
}

/** The form, filled with the client's details and the correction made so far. */
export function correctionForm(client: ClientData, correction: ClientCorrection = {}): CorrectionForm {
    const form = {} as CorrectionForm;
    for (const field of CORRECTABLE) {
        form[field] = (field in correction ? correction[field] : client[field]) ?? '';
    }
    return form;
}

/** What differs from the client; empty when the form is back to the client's details. */
export function correctionDiff(client: ClientData, form: CorrectionForm): ClientCorrection {
    const correction: ClientCorrection = {};
    for (const field of CORRECTABLE) {
        let value = normalise(form[field]);
        if (field === 'email' && value !== null) value = value.toLowerCase();
        if (value !== current(client, field)) correction[field] = value;
    }
    return correction;
}

export function hasCorrection(correction: ClientCorrection | undefined): correction is ClientCorrection {
    return correction !== undefined && Object.keys(correction).length > 0;
}

/** The client as it will be once the correction is applied. */
export function corrected(client: ClientData, correction: ClientCorrection | undefined): ClientData {
    return hasCorrection(correction) ? { ...client, ...correction } : client;
}

/** The correction still waiting on an order, to edit it again. */
export function correctionOf(change: ClientChangeView | null | undefined): ClientCorrection | undefined {
    if (!change || change.status !== 'pending') return undefined;
    const correction: ClientCorrection = {};
    // Only the correctable fields: the answer also carries old names beside them (docs/API.md "Old field names").
    for (const [field, pair] of Object.entries(change.changes)) if ((CORRECTABLE as readonly string[]).includes(field)) correction[field as ClientField] = pair.to;
    return correction;
}

/** The pending change as the API shows it, for an order the phone holds. */
export function pendingChange(client: ClientData, correction: ClientCorrection): ClientChangeView {
    const changes: ClientChangeView['changes'] = {};
    for (const field of CORRECTABLE) {
        if (field in correction) changes[field] = { from: current(client, field), to: correction[field] ?? null };
    }
    return { status: 'pending', changes, error: null, decided_by: null, applied_at: null };
}

export function isFrenchPhone(value: string, mobile = false): boolean {
    const digits = value.trim().replace(/[\s.\-/()]+/g, '');
    if (mobile) return /^(?:\+33|0033|33)?0?[67]\d{8}$/.test(digits) && digits.length !== 9;
    return /^(?:0|\+33|0033)[1-9]\d{8}$/.test(digits);
}

/** Field errors, in French, before the correction is kept. */
export function correctionErrors(client: ClientData, form: CorrectionForm): Partial<Record<ClientField, string>> {
    const errors: Partial<Record<ClientField, string>> = {};
    const value = (field: ClientField): string => form[field].trim();

    if (!/^\d{5}$/.test(value('postcode'))) errors.postcode = t('5 digits.');
    if (!value('town')) errors.town = t('Required.');
    if (value('phone') && !isFrenchPhone(value('phone'))) errors.phone = t('Enter a French phone number.');
    if (value('mobile') && !isFrenchPhone(value('mobile'), true)) errors.mobile = t('Enter a French mobile number (06 or 07).');
    if (value('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value('email'))) errors.email = t('Enter a valid email address.');
    for (const field of ['contact_name', 'email'] as const) {
        if (!value(field) && current(client, field) !== null) errors[field] = t('This field can be replaced, not removed.');
    }
    return errors;
}
