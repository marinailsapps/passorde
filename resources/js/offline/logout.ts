import { router } from '@inertiajs/vue3';

import { forgetUser, isOffline, offline } from './runtime';
import { t, tc } from '@/lib/i18n';

/**
 * Logging out wipes this user's data from the phone. Orders still in the
 * outbox would be lost, so ask first; offline, the server cannot end the
 * session, so wait for the network.
 */
export async function logout(): Promise<void> {
    if (isOffline()) {
        window.alert(t('Logging out needs the network.'));
        return;
    }
    if (offline.pending > 0 && !window.confirm(tc(':count orders not sent yet will be lost on this phone. Log out anyway?', offline.pending))) {
        return;
    }
    await forgetUser();
    router.post('/logout');
}
