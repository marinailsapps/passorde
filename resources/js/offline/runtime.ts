import { router } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';
import Dexie from 'dexie';
import { reactive } from 'vue';

import { clearSession, deleteUserDb, type LocalOrder, type MirrorArticle, type MirrorClient, readSession, type SessionRecord, type UserDb, userDb, writeSession } from './db';
import { HISTORY_SYNCED_AT, SYNCED_AT, syncMirror } from './mirror';
import { isLocalOnly, type OrderBody, queueDelete, queueSave } from './orders';
import { AUTH_PAUSED, resumeAfterLogin, runOutbox, type RunResult, type Transport } from './outbox';
import { buildLocalPage, matchRoute } from './pages';
import { searchLocalArticles, searchLocalClients } from './search';
import { storage } from '../lib/brand';
import { ApiError, apiGet } from '@/lib/api';
import { historyEntries, recentFromHistory } from '@/lib/salesHistory';
import type { ArticleHit, AuthUser, ClientData, HistoryEntry, RecentOrder } from '@/types';

/**
 * Browser side of offline mode: who is signed in, the sync pill state, when
 * to send the outbox and refresh the mirror, search with a local fallback,
 * and Inertia visits rendered from local data when the server is out of reach.
 */

export const REFRESH_MS = 15 * 60_000;
const SEARCH_TIMEOUT_MS = 6000;
/** A request that hangs (weak signal, captive Wi-Fi) counts as a network error after this. */
const SEND_TIMEOUT_MS = 15_000;
const MIRROR_TIMEOUT_MS = 30_000;
/** When the service worker holds the outbox, the page looks again after this. */
const LOCK_BUSY_RETRY_MS = 3000;
/** Extra sends after the "online" event, which often fires before requests get through. */
const ONLINE_RETRY_MS = [2000, 6000];
/** How long "Confirmer" waits for the server before showing "En attente de réseau". */
const CONFIRM_WAIT_MS = 3000;

export const offline = reactive({
    userId: null as number | null,
    /** navigator.onLine */
    online: typeof navigator === 'undefined' || navigator.onLine !== false,
    /** False after a request failed to reach the server, until one succeeds. */
    reachable: true,
    sending: false,
    syncing: false,
    /** Orders waiting in the outbox. */
    pending: 0,
    /** Orders the server refused ("Non envoyée"). */
    unsent: 0,
    authExpired: false,
    /** When the mirror was last refreshed (ISO). */
    syncedAt: null as string | null,
    /** Bumped whenever local orders change, so pages re-read them. */
    revision: 0,
    /** Uuids of orders only the phone knows; their pages are always rendered locally. */
    localOnly: new Set<string>(),
});

export const isOffline = (): boolean => !offline.online || !offline.reachable;

let db: UserDb | null = null;
let session: SessionRecord | null = null;
let retryTimer: ReturnType<typeof setTimeout> | undefined;
let listening = false;

export const currentDb = (): UserDb | null => db;

// Pages mount before the user's database is open on a fresh load: local reads wait for it.
let markReady: () => void = () => undefined;
const ready = new Promise<void>((resolve) => (markReady = resolve));

/** The local database once open (at most a few seconds' wait), or null when nobody is signed in. */
async function openDb(): Promise<UserDb | null> {
    if (!db) await Promise.race([ready, new Promise((resolve) => setTimeout(resolve, 3000))]);
    return db;
}

/* Transport */

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** The request in flight, aborted when the network comes back so the retry starts at once. */
let inflight: AbortController | null = null;

/** fetch for the outbox: any HTTP status resolves, an unreachable server (or a hung request) rejects. */
export const browserTransport: Transport = {
    async send(method, path, body) {
        const send = (): Promise<Response> => {
            const controller = new AbortController();
            inflight = controller;
            const timer = setTimeout(() => controller.abort(), SEND_TIMEOUT_MS);
            return fetch(path, {
                method,
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
                body: body === null || body === undefined ? undefined : JSON.stringify(body),
            }).finally(() => {
                clearTimeout(timer);
                if (inflight === controller) inflight = null;
            });
        };

        let response: Response;
        try {
            response = await send();
            // An expired CSRF cookie: any GET hands out a fresh one, then try once more.
            if (response.status === 419) {
                await fetch('/api/paytypes', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                response = await send();
            }
        } catch (error) {
            offline.reachable = false;
            throw error;
        }

        offline.reachable = true;
        // A request the server bounced to the login page (deactivated account): the session is gone.
        if (redirectedToLogin(response)) return { status: 401, body: null };
        return { status: response.status, body: response.status === 204 ? null : await response.json().catch(() => null) };
    },
};

const redirectedToLogin = (response: Response): boolean => response.redirected && new URL(response.url).pathname === '/login';

const getter = <T>(path: string, query: Record<string, string | undefined>): Promise<T> => withTimeout(apiGet<T>(path, query), MIRROR_TIMEOUT_MS);

function noteFailure(error: unknown): void {
    if (error instanceof ApiError) {
        offline.reachable = true;
        if (error.status === 401 || error.status === 419) void sessionRejected();
    } else {
        offline.reachable = false;
    }
}

/* Session */

/**
 * Called with every page that came from the server: remembers the user and
 * the shared props for offline starts, and switches the local data when a
 * different user signs in.
 */
export async function rememberServerPage(page: Page): Promise<void> {
    const user = (page.props.auth as { user: AuthUser | null } | undefined)?.user ?? null;
    if ((page.props as { offline?: boolean }).offline) return;
    // The server shows the login page although this phone had a user: the session was ended there.
    if (user === null) {
        if (page.component === 'Auth/Login') await sessionRejected(true);
        return;
    }

    const previous = await readSession();
    session = {
        key: 'current',
        user,
        appVersion: String(page.props.appVersion ?? ''),
        turnstileSiteKey: (page.props.turnstileSiteKey as string | null) ?? null,
        evolizWritesEnabled: page.props.evolizWritesEnabled !== false,
        smsEnabled: page.props.smsEnabled === true,
        syncProvider: page.props.syncProvider === 'file' ? 'file' : 'evoliz',
        clientExtraField: (page.props.clientExtraField as SessionRecord['clientExtraField']) ?? null,
        wording: (page.props.wording as SessionRecord['wording']) ?? null,
        catalog: (page.props.catalog as SessionRecord['catalog']) ?? null,
        version: page.version ?? null,
    };
    await writeSession(session).catch(() => undefined);

    // Another user signs in on this phone: drop the previous user's data unless it still has orders to send.
    if (previous && previous.user.id !== user.id) {
        const old = userDb(previous.user.id);
        if ((await old.outbox.count().catch(() => 0)) === 0) await deleteUserDb(previous.user.id).catch(() => undefined);
    }

    await start(user, true);
}

export async function start(user: AuthUser, fromServer: boolean): Promise<void> {
    const switching = offline.userId !== user.id;
    offline.userId = user.id;
    db = userDb(user.id);
    markReady();

    // A page from the server proves the session works: resume a paused outbox.
    if (fromServer && (offline.authExpired || (await db.getMeta<boolean>(AUTH_PAUSED)))) {
        await resumeAfterLogin(db);
        offline.authExpired = false;
        void kickOutbox(true);
    }

    if (!switching) return;

    offline.syncedAt = (await db.getMeta<string>(SYNCED_AT)) ?? null;
    offline.authExpired = (await db.getMeta<boolean>(AUTH_PAUSED)) ?? false;
    invalidateCaches();
    await refreshCounts();
    listen();
    void kickOutbox(true);
    void refreshMirror();
}

/** Offline start: the user of the last online page, if any. */
export async function startFromSession(): Promise<SessionRecord | null> {
    session = await readSession();
    if (session) await start(session.user, false);
    return session;
}

/** Logout: the phone forgets this user's data (the signed-in user's, or the given one's). */
export async function forgetUser(userId: number | null = null): Promise<void> {
    const id = userId ?? offline.userId;
    clearTimeout(retryTimer);
    db = null;
    session = null;
    offline.userId = null;
    offline.pending = 0;
    offline.unsent = 0;
    offline.localOnly = new Set();
    invalidateCaches();
    if (id !== null) await deleteUserDb(id).catch(() => undefined);
    await clearSession().catch(() => undefined);
}

/* Triggers */

function listen(): void {
    if (listening || typeof window === 'undefined') return;
    listening = true;

    window.addEventListener('online', () => {
        offline.online = true;
        offline.reachable = true;
        // A request stuck from before would hold the queue up to its timeout.
        inflight?.abort();
        void kickOutbox(true);
        void refreshMirror();
        // The connection is often not usable yet when "online" fires: try again shortly.
        for (const delay of ONLINE_RETRY_MS) setTimeout(() => void kickOutbox(true), delay);
    });
    window.addEventListener('offline', () => (offline.online = false));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        void kickOutbox(true);
        if (isStale()) void refreshMirror();
    });
    // Every 15 minutes while the app is visible.
    setInterval(() => {
        if (document.visibilityState === 'visible' && isStale()) void refreshMirror();
    }, 60_000);
    // The service worker sent (or tried to send) the outbox in the background (Background Sync).
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if ((event.data as { type?: string } | null)?.type !== 'outbox-changed') return;
        void refreshCounts();
        // Whatever is left keeps its retry schedule on this side too.
        if (db) void scheduleRetry(db);
    });
}

const isStale = (): boolean => offline.syncedAt === null || Date.now() - new Date(offline.syncedAt).getTime() >= REFRESH_MS;

/* Outbox */

let running: Promise<RunResult | null> | null = null;
let again = false;

async function withLock<T>(task: () => Promise<T>): Promise<T | null> {
    const locks = (typeof navigator !== 'undefined' ? navigator.locks : undefined) as LockManager | undefined;
    if (!locks) return task();
    // Shared with the service worker, so the page and Background Sync never send at the same time.
    return locks.request(storage.outboxLock, { ifAvailable: true }, async (lock) => (lock ? task() : null));
}

/** Sends the outbox now (force: ignore the backoff). Concurrent calls share one run. */
export function kickOutbox(force = false): Promise<RunResult | null> {
    if (running) {
        again ||= force;
        return running;
    }

    // Nobody signed in (login page): nothing to send, and no run left behind to join.
    const target = db;
    if (!target) return Promise.resolve(null);

    running = (async () => {
        try {
            offline.sending = (await target.outbox.count()) > 0;
            const result = await withLock(() => runOutbox({ db: target, transport: browserTransport, force }));
            if (result === null) {
                // The service worker (Background Sync) is sending right now: look again shortly.
                clearTimeout(retryTimer);
                retryTimer = setTimeout(() => void kickOutbox(force), LOCK_BUSY_RETRY_MS);
            }
            if (result?.outcome === 'auth') {
                offline.authExpired = true;
                // Offline, a pause from before is all we know: wait for the server to say so again.
                if (!isOffline()) void sessionRejected();
            }
            if (result?.outcome === 'retrying') await scheduleRetry(target);
            return result;
        } catch {
            // The database went away mid-run (logout, another user signing in): nothing more to send here.
            return null;
        } finally {
            offline.sending = false;
            running = null;
            await refreshCounts();
            if (again) {
                again = false;
                void kickOutbox(true);
            }
        }
    })();

    return running;
}

async function scheduleRetry(target: UserDb): Promise<void> {
    clearTimeout(retryTimer);
    const head = await target.outbox.orderBy('seq').first();
    if (head) retryTimer = setTimeout(() => void kickOutbox(false), Math.max(1000, head.nextAttemptAt - Date.now()));

    // Android: let the service worker send it even if the app is closed. Never wait for
    // serviceWorker.ready here: it stays pending while no worker is active.
    void navigator.serviceWorker
        ?.getRegistration()
        .then((registration) => (registration as (ServiceWorkerRegistration & { sync?: { register(tag: string): Promise<void> } }) | undefined)?.sync?.register('outbox'))
        .catch(() => undefined); // No Background Sync (iOS, Firefox): the app sends on its next start.
}

export async function refreshCounts(): Promise<void> {
    if (!db) return;
    const [entries, orders] = await Promise.all([db.outbox.toArray(), db.orders.toArray()]);
    offline.pending = new Set(entries.map((entry) => entry.uuid)).size;
    offline.unsent = orders.filter((order) => order.local_state === 'unsent' && !order.deleted_at).length;
    offline.localOnly = new Set(orders.filter(isLocalOnly).map((order) => order.uuid));
    offline.revision++;
}

/**
 * "Confirmer": local copy + outbox, then an immediate send. Waits a few
 * seconds at most (not at all when known to be offline): the send goes on in
 * the background and the number shows up when it lands.
 */
export async function confirmOrder(order: LocalOrder, body: OrderBody, onStored?: () => void): Promise<void> {
    if (!(await openDb()) || !db) throw new Error('No local database');
    await queueSave(db, order, body);
    onStored?.();
    invalidateCaches();
    await refreshCounts();
    const sending = kickOutbox(true);
    await Promise.race([sending, new Promise((resolve) => setTimeout(resolve, isOffline() ? 0 : CONFIRM_WAIT_MS))]);
}

export async function deleteOrder(uuid: string): Promise<void> {
    if (!(await openDb()) || !db) throw new Error('No local database');
    // A server order not mirrored yet still needs a local row to hide.
    if (!(await db.orders.get(uuid))) {
        await db.outbox.add({ uuid, method: 'DELETE', path: `/api/orders/${uuid}`, body: null, attempts: 0, lastError: null, createdAt: Date.now(), nextAttemptAt: 0 });
    } else {
        await queueDelete(db, uuid);
    }
    await refreshCounts();
    void kickOutbox(true);
}

/* Session ended by the server (lost phone, docs/RUNBOOK.md "Lost or stolen phone") */

let rejecting: Promise<void> | null = null;

/**
 * The server no longer accepts this phone's session: an API call answered
 * 401/419, or a server page came back signed out while the phone still had a
 * user (deactivated account, password changed elsewhere).
 *
 *  - Nothing waiting to be sent: the phone deletes the user's local data and
 *    goes to the login page.
 *  - Orders waiting: everything stays. The login page lists them under
 *    "Commandes non envoyées", keeps trying to send them (they go as soon as
 *    that user signs in again) and offers "Effacer les données de ce téléphone".
 *
 * A page with unsaved input (the order form) is not left behind the user's
 * back: the pill shows "Session expirée" and the next visit lands on the login page.
 */
export function sessionRejected(onLoginPage = false): Promise<void> {
    rejecting ??= (async () => {
        const stored = session ?? (await readSession());
        if (!stored) return;
        offline.authExpired = true;
        const waiting = await userDb(stored.user.id).outbox.count().catch(() => 0);
        if (waiting === 0) await forgetUser(stored.user.id);
        if (!onLoginPage && guards.size === 0 && typeof window !== 'undefined') window.location.assign('/login');
    })().finally(() => (rejecting = null));
    return rejecting;
}

export interface StrandedOrders {
    userName: string;
    orders: LocalOrder[];
}

/** Orders of the phone's last user still waiting to be sent, for the login page. */
export async function strandedOrders(): Promise<StrandedOrders | null> {
    const stored = await readSession();
    if (!stored) return null;
    const target = userDb(stored.user.id);
    const uuids = [...new Set((await target.outbox.toArray().catch(() => [])).map((entry) => entry.uuid))];
    if (uuids.length === 0) return null;
    const orders = (await target.orders.bulkGet(uuids)).filter((order): order is LocalOrder => order !== undefined);
    return { userName: stored.user.name, orders };
}

/** One more attempt at the stranded orders (the session may be back). */
export async function retryStranded(): Promise<RunResult | null> {
    const stored = await readSession();
    if (!stored) return null;
    const target = userDb(stored.user.id);
    if ((await target.outbox.count()) === 0) return null;
    await resumeAfterLogin(target);
    return withLock(() => runOutbox({ db: target, transport: browserTransport, force: true }));
}

/** "Effacer les données de ce téléphone": every user's local data and the remembered session. */
export async function wipePhone(): Promise<void> {
    const stored = await readSession();
    await forgetUser(stored?.user.id ?? null);
    offline.authExpired = false;
    // Leftovers of other users on a shared phone, where the browser can list its databases.
    const listed = typeof indexedDB !== 'undefined' && typeof indexedDB.databases === 'function' ? await indexedDB.databases().catch(() => []) : [];
    for (const { name } of listed) {
        if (name?.startsWith(storage.userDbPrefix)) await Dexie.delete(name).catch(() => undefined);
    }
}

/* Mirror */

let syncing: Promise<void> | null = null;

export function refreshMirror(): Promise<void> {
    if (syncing) return syncing;
    if (!db || !offline.online) return Promise.resolve();
    const target = db;
    offline.syncing = true;
    syncing = (async () => {
        try {
            await syncMirror(target, getter);
            offline.syncedAt = (await target.getMeta<string>(SYNCED_AT)) ?? null;
            offline.reachable = true;
        } catch (error) {
            noteFailure(error);
        } finally {
            offline.syncing = false;
            syncing = null;
            invalidateCaches();
            await refreshCounts();
        }
    })();
    return syncing;
}

/* Search with a local fallback */

let clientCache: MirrorClient[] | null = null;
let articleCache: MirrorArticle[] | null = null;

function invalidateCaches(): void {
    clientCache = null;
    articleCache = null;
}

const localClients = async (): Promise<MirrorClient[]> => (clientCache ??= (await (await openDb())?.clients.toArray()) ?? []);
const localArticles = async (): Promise<MirrorArticle[]> => (articleCache ??= (await (await openDb())?.articles.toArray()) ?? []);

function withTimeout<T>(promise: Promise<T>, ms = SEARCH_TIMEOUT_MS): Promise<T> {
    return Promise.race([promise, new Promise<T>((_, reject) => setTimeout(() => reject(new Error('timeout')), ms))]);
}

export async function searchClients(query: string): Promise<ClientData[]> {
    // Sessions stored before the field was configurable searched it: keep that until the next online page.
    const local = searchLocalClients(await localClients(), query, session?.clientExtraField === undefined || session.clientExtraField?.searchable === true);
    if (!isOffline()) {
        try {
            const { data } = await withTimeout(apiGet<{ data: ClientData[] }>('/api/clients/search', { q: query }));
            // Clients typed on the phone are not on the server yet: list them first.
            return [...local.filter((client) => client.id === 0), ...data];
        } catch (error) {
            noteFailure(error);
        }
    }
    return local;
}

export async function searchArticles(query: string): Promise<ArticleHit[]> {
    if (!isOffline()) {
        try {
            return (await withTimeout(apiGet<{ data: ArticleHit[] }>('/api/articles/search', { q: query }))).data;
        } catch (error) {
            noteFailure(error);
        }
    }
    return searchLocalArticles(await localArticles(), query, offline.syncedAt);
}

/** The client's history from the phone's copy (BLs and this user's own orders). */
async function localHistory(clientId: number): Promise<HistoryEntry[]> {
    const target = await openDb();
    if (!target) return [];
    const deliveries = await target.deliveries.where('client_id').equals(clientId).toArray();
    const lines = deliveries.length ? await target.delivery_lines.where('delivery_id').anyOf(deliveries.map((delivery) => delivery.id)).toArray() : [];
    const orders = (await target.orders.toArray()).filter((order) => order.client.id === clientId);
    return historyEntries(deliveries, lines, orders);
}

/** Whether the phone holds the whole BL history yet (the first load takes a while). */
export async function historyMirrored(): Promise<boolean> {
    return (await (await openDb())?.getMeta<string>(HISTORY_SYNCED_AT)) !== undefined;
}

/** "Historique": the server's timeline, or the phone's copy offline. */
export async function clientHistory(clientId: number): Promise<{ entries: HistoryEntry[]; local: boolean }> {
    if (!isOffline()) {
        try {
            return { entries: (await withTimeout(apiGet<{ data: HistoryEntry[] }>(`/api/clients/${clientId}/history`))).data, local: false };
        } catch (error) {
            noteFailure(error);
        }
    }
    return { entries: await localHistory(clientId), local: true };
}

/** "Copier une commande précédente": the server's last 3 history entries, or the phone's copy. */
export async function recentOrders(clientId: number): Promise<RecentOrder[]> {
    if (!isOffline()) {
        try {
            return (await withTimeout(apiGet<{ data: RecentOrder[] }>(`/api/clients/${clientId}/recent-orders`))).data;
        } catch (error) {
            noteFailure(error);
        }
    }
    const target = await openDb();
    if (!target) return [];
    const articles = new Map((await localArticles()).map((article) => [article.id, article]));
    const orders = (await target.orders.toArray()).filter((order) => order.client.id === clientId);
    return recentFromHistory(await localHistory(clientId), articles, orders);
}

export async function localOrder(uuid: string): Promise<LocalOrder | null> {
    return (await (await openDb())?.orders.get(uuid)) ?? null;
}

export async function localOrders(): Promise<LocalOrder[]> {
    const target = await openDb();
    if (!target) return [];
    return (await target.orders.toArray()).filter((order) => !order.deleted_at);
}

export async function outboxFor(uuid: string): Promise<{ attempts: number; lastError: string | null; nextAttemptAt: number } | null> {
    const target = await openDb();
    const entries = target ? await target.outbox.where('uuid').equals(uuid).toArray() : [];
    if (entries.length === 0) return null;
    const last = entries[entries.length - 1];
    return { attempts: last.attempts, lastError: last.lastError, nextAttemptAt: last.nextAttemptAt };
}

/* Inertia: pages rendered from local data */

type LeaveGuard = () => boolean;
const guards = new Set<LeaveGuard>();

/** A page that may refuse to be left (unsaved order). Returns the unregister function. */
export function registerLeaveGuard(guard: LeaveGuard): () => void {
    guards.add(guard);
    return () => guards.delete(guard);
}

export async function localPage(url: URL): Promise<Page | null> {
    if (!db || !session) await startFromSession();
    if (!db || !session) return null;
    return buildLocalPage(url, db, session);
}

async function renderLocally(url: URL, replace = false): Promise<void> {
    const page = await localPage(url);
    if (!page) {
        window.location.href = url.href;
        return;
    }
    const visit = { url: page.url, component: page.component, props: page.props, preserveScroll: false, preserveState: false };
    if (replace) router.replace(visit);
    else router.push(visit);
}

/** Pages of orders only the phone has: the server would answer 404. */
const needsLocalRender = (url: URL): boolean => {
    const route = matchRoute(url);
    if (route.name === 'show' || route.name === 'edit') return offline.localOnly.has(route.uuid);
    return route.name === 'create' && route.from !== null && offline.localOnly.has(route.from);
};

export function installOfflineRouter(): void {
    let pending: { url: URL; navigation: boolean; replace: boolean } | null = null;
    // Partial reloads (polling) and prefetches are not navigations.
    const isNavigation = (visit: { method: string; only: string[]; prefetch: boolean }): boolean => visit.method === 'get' && visit.only.length === 0 && !visit.prefetch;

    router.on('before', (event) => {
        const visit = event.detail.visit;
        if (!isNavigation(visit)) {
            // No point polling the server while offline.
            return visit.method === 'get' && isOffline() ? false : undefined;
        }
        for (const guard of guards) {
            if (!guard()) return false;
        }
        if (visit.url.origin !== window.location.origin) return;
        if (isOffline() || needsLocalRender(visit.url)) {
            void renderLocally(visit.url, visit.replace);
            return false;
        }
    });

    router.on('start', (event) => {
        const visit = event.detail.visit;
        pending = { url: visit.url, navigation: isNavigation(visit), replace: visit.replace };
    });

    // The server could not be reached: render the page from local data instead of an error.
    router.on('exception', (event) => {
        const exception = event.detail.exception as { response?: unknown };
        if (exception?.response || !pending) return;
        offline.reachable = false;
        if (pending.navigation) void renderLocally(pending.url, pending.replace);
        return false;
    });
}
