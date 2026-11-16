import { usePage } from '@inertiajs/vue3';

import { t } from '@/lib/i18n';

/** True while PASSORDE_WRITES_ENABLED=false: approving creates nothing in Evoliz. */
export const isTestMode = (): boolean => usePage().props.evolizWritesEnabled === false;

/** Sentence that opens the approval dialogs in test mode. */
export const testModeApprovalNote = (count = 1): string =>
    isTestMode() ? t(count > 1 ? 'Test mode: nothing is sent to Evoliz, these orders will be marked failed.' : 'Test mode: nothing is sent to Evoliz, the order will be marked failed.') : '';
