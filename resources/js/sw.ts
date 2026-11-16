/// <reference lib="webworker" />
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';

import { readSession, userDb } from './offline/db';
import { runOutbox, type Transport } from './offline/outbox';
import { storage } from './lib/brand';

/**
 * Service worker. Precaches the built assets, the icons and the app shell
 * (/app-shell: the root HTML without any user data). Authenticated pages and
 * API answers are never cached: the phone's data lives in IndexedDB. When a
 * navigation cannot reach the server, the shell is served and the app renders
 * the page from local data (resources/js/offline).
 */

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: (string | { url: string; revision: string | null })[] };

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// A new version waits until the app is next launched, or until the user taps "Recharger".
self.addEventListener('message', (event) => {
    if ((event.data as { type?: string } | null)?.type === 'SKIP_WAITING') void self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(async () => (await matchPrecache('/app-shell')) ?? Response.error()));
});

/* Background Sync (Android): send the outbox while the app is closed. */

interface SyncEvent extends ExtendableEvent {
    tag: string;
}

async function xsrfToken(): Promise<string> {
    try {
        const store = (self as unknown as { cookieStore?: { get(name: string): Promise<{ value: string } | null> } }).cookieStore;
        const cookie = await store?.get('XSRF-TOKEN');
        return cookie ? decodeURIComponent(cookie.value) : '';
    } catch {
        return '';
    }
}

const transport: Transport = {
    async send(method, path, body) {
        const response = await fetch(path, {
            method,
            credentials: 'same-origin',
            // A hung request must not hold the outbox lock (shared with the page).
            signal: AbortSignal.timeout(15_000),
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': await xsrfToken() },
            body: body === null || body === undefined ? undefined : JSON.stringify(body),
        });
        return { status: response.status, body: response.status === 204 ? null : await response.json().catch(() => null) };
    },
};

async function sendOutbox(): Promise<void> {
    const session = await readSession();
    if (!session) return;

    const send = () => runOutbox({ db: userDb(session.user.id), transport, force: true });
    const locks = (self.navigator as Navigator & { locks?: LockManager }).locks;
    // The same lock as the page, so the two never send at the same time.
    const result = locks ? await locks.request(storage.outboxLock, { ifAvailable: true }, (lock) => (lock ? send() : null)) : await send();

    for (const client of await self.clients.matchAll({ type: 'window' })) {
        client.postMessage({ type: 'outbox-changed' });
    }

    // Rejecting asks the browser to fire the sync again later.
    if (result?.outcome === 'retrying') throw new Error('Outbox not sent yet');
}

self.addEventListener('sync', (event) => {
    const sync = event as SyncEvent;
    if (sync.tag === 'outbox') sync.waitUntil(sendOutbox());
});
