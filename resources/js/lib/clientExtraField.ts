import { usePage } from '@inertiajs/vue3';

import { t } from '@/lib/i18n';
import type { ClientExtraField } from '@/types';

/**
 * The installation's optional client field (App\Domain\Clients\ClientExtraField,
 * shared prop clientExtraField, kept in the offline session): its label and
 * whether the client search reads it; null when the profile has none. Its value
 * travels as `extra_field`, whatever the label says.
 */
export function clientExtraField(): ClientExtraField | null {
    try {
        return (usePage()?.props?.clientExtraField as ClientExtraField | null | undefined) ?? null;
    } catch {
        return null;
    }
}

/** The field's label, or a neutral one where a text must name it while it is off. */
export const clientExtraLabel = (): string => clientExtraField()?.label ?? t('Client field');

/** The client search field's label: names the optional field when the search reads it. */
export function clientSearchLabel(): string {
    const field = clientExtraField();
    return field?.searchable ? t('Name, town, phone or :field', { field: field.label }) : t('Name, town or phone');
}
