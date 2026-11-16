import type { LocalOrder, OutboxEntry, UserDb } from './db';
import type { ClientData, OrderDetail } from '@/types';

/**
 * The outbox: orders confirmed on the phone wait here until the server has
 * them. Entries are replayed in creation order, one at a time, exactly as
 * docs/API.md describes (PUT and DELETE /api/orders/{uuid}, both safe to
 * replay):
 *
 *  - 2xx: done. The entry goes and the local order becomes the server's copy.
 *  - 401 / 419: the session is gone. Stop, keep everything, wait for a login.
 *  - 403 / 409 / 422 (any other 4xx): final. The entry goes; the order stays
 *    on the phone flagged "Non envoyée" with the message, to be fixed.
 *  - network error, 408, 429, 5xx: keep and retry later with backoff
 *    (10 s, 30 s, 2 min, then every 5 min), never give up.
 *
 * This module has no browser globals so it also runs in the service worker
 * (Background Sync) and in Vitest.
 */

export interface TransportResponse {
    status: number;
    body: unknown;
}

export interface Transport {
    /** Resolves with any HTTP status; rejects only when the server could not be reached. */
    send(method: OutboxEntry['method'], path: string, body: unknown): Promise<TransportResponse>;
}

export type RunOutcome = 'done' | 'waiting' | 'retrying' | 'auth';

export interface RunResult {
    outcome: RunOutcome;
    sent: number;
    refused: number;
}

export const BACKOFF_MS = [10_000, 30_000, 120_000, 300_000];

/** Delay before the next automatic attempt, after `attempts` failures. */
export function backoffDelay(attempts: number): number {
    return BACKOFF_MS[Math.min(Math.max(attempts, 1), BACKOFF_MS.length) - 1];
}

export const AUTH_PAUSED = 'outbox.authPaused';

export interface RunOptions {
    db: UserDb;
    transport: Transport;
    now?: () => number;
    /** Ignore the backoff (app opened, back online, confirm pressed). */
    force?: boolean;
}

export async function runOutbox({ db, transport, now = Date.now, force = false }: RunOptions): Promise<RunResult> {
    const result: RunResult = { outcome: 'done', sent: 0, refused: 0 };

    if (await db.getMeta<boolean>(AUTH_PAUSED)) {
        return { ...result, outcome: 'auth' };
    }

    for (;;) {
        const entry = await db.outbox.orderBy('seq').first();
        if (!entry) return result;

        if (!force && entry.nextAttemptAt > now()) {
            return { ...result, outcome: 'waiting' };
        }

        let response: TransportResponse;
        try {
            response = await transport.send(entry.method, entry.path, entry.body);
        } catch (error) {
            await retryLater(db, entry, error instanceof Error && error.message ? error.message : 'Network error', now());
            return { ...result, outcome: 'retrying' };
        }

        const { status, body } = response;

        // A 2xx without an order (a captive portal's page, say) is not the API: try again later.
        if (status >= 200 && status < 300 && entry.method === 'PUT' && !(body as { order?: unknown } | null)?.order) {
            await retryLater(db, entry, `HTTP ${status}: unexpected response`, now());
            return { ...result, outcome: 'retrying' };
        }

        if (status >= 200 && status < 300) {
            await applySuccess(db, entry, body);
            result.sent++;
            continue;
        }

        if (status === 401 || status === 419) {
            await db.transaction('rw', db.outbox, db.meta, async () => {
                await db.outbox.update(entry.seq!, { lastError: messageOf(body, status) });
                await db.setMeta(AUTH_PAUSED, true);
            });
            return { ...result, outcome: 'auth' };
        }

        // A DELETE of an order the server never had is as good as done.
        if (entry.method === 'DELETE' && status === 404) {
            await applySuccess(db, entry, body);
            result.sent++;
            continue;
        }

        if (status >= 400 && status < 500 && status !== 408 && status !== 429) {
            await applyRefusal(db, entry, messageOf(body, status));
            result.refused++;
            continue;
        }

        await retryLater(db, entry, messageOf(body, status), now());
        return { ...result, outcome: 'retrying' };
    }
}

/** Resumes after a login. */
export async function resumeAfterLogin(db: UserDb): Promise<void> {
    await db.setMeta(AUTH_PAUSED, false);
}

async function retryLater(db: UserDb, entry: OutboxEntry, message: string, now: number): Promise<void> {
    const attempts = entry.attempts + 1;
    await db.outbox.update(entry.seq!, { attempts, lastError: message, nextAttemptAt: now + backoffDelay(attempts) });
}

async function applySuccess(db: UserDb, entry: OutboxEntry, body: unknown): Promise<void> {
    await db.transaction('rw', db.outbox, db.orders, db.clients, async () => {
        await db.outbox.delete(entry.seq!);
        const later = await db.outbox.where('uuid').equals(entry.uuid).count();

        if (entry.method === 'DELETE') {
            if (later === 0) await db.orders.delete(entry.uuid);
            return;
        }

        const payload = body as { order?: OrderDetail; possible_duplicate?: ClientData | null };
        if (!payload?.order) return;

        const server: LocalOrder = { ...payload.order, deleted_at: null, local_state: 'synced', local_error: null, possible_duplicate: payload.possible_duplicate ?? null };
        const local = await db.orders.get(entry.uuid);

        // A later edit is still queued: keep its content, take the server's number and status.
        if (later > 0 && local) {
            await db.orders.put({ ...local, number: server.number, status: server.status, display_status: server.display_status, status_label: server.status_label, created_at: server.created_at });
        } else if (server.author.id !== db.userId) {
            // The operator corrected a seller's order: the phone mirrors only its user's own orders.
            await db.orders.delete(entry.uuid);
        } else {
            await db.orders.put(server);
        }

        // A client typed on the phone now exists on the server: the mirror gets it at the next sync.
        const typed = server.client.uuid;
        if (typed && server.client.id) {
            await db.clients.delete(`uuid:${typed}`);
            await db.clients.put({ ...server.client, key: `id:${server.client.id}`, is_active: true, updated_at: null });
        }
    });
}

async function applyRefusal(db: UserDb, entry: OutboxEntry, message: string): Promise<void> {
    await db.transaction('rw', db.outbox, db.orders, async () => {
        await db.outbox.delete(entry.seq!);
        const local = await db.orders.get(entry.uuid);
        if (local) {
            // A refused delete brings the order back into view.
            await db.orders.put({ ...local, deleted_at: null, local_state: 'unsent', local_error: message });
        }
    });
}

/** The French message of an API error body: field messages first, else `message`. */
export function messageOf(body: unknown, status: number): string {
    const data = (body ?? {}) as { message?: unknown; errors?: Record<string, string[]> };
    const fields = Object.values(data.errors ?? {})
        .map((messages) => messages?.[0])
        .filter((message): message is string => typeof message === 'string');

    if (fields.length > 0) return fields.join(' ');
    return typeof data.message === 'string' && data.message !== '' ? data.message : `HTTP ${status}`;
}
