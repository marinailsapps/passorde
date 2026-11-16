import Dexie, { type Table } from 'dexie';

import { readArticle, readClient, readOrder } from './legacy';
import { storage } from '../lib/brand';
import type { ArticleHit, AuthUser, CatalogProp, ClientData, ClientExtraField, DeliveryLineRow, DeliveryRow, OrderDetail, Paytype, SyncProvider, Wording } from '@/types';

/**
 * The phone's local data (IndexedDB through Dexie). One database per user,
 * `<prefix>-user-<id>` (prefix: phone.storage_prefix, lib/brand.ts), so a shared phone never shows one user's orders to
 * another; it is deleted on logout. A small `<prefix>` database remembers who
 * is signed in, for offline starts and for the service worker.
 */

/** A client of the directory, or one typed on the phone (`key` "uuid:…", id 0) until the server knows it. */
export interface MirrorClient extends ClientData {
    key: string;
    is_active: boolean;
    updated_at: string | null;
    /** Typed on the phone and not yet received by the server. */
    pending?: boolean;
}

export interface MirrorArticle extends ArticleHit {
    is_active: boolean;
    updated_at: string | null;
    /** Report attributes (sales report offline); absent on rows mirrored by an older version. */
    category?: string | null;
    variant?: string | null;
}

/** A mirrored Evoliz BL; deleted ones are removed, so deleted_at is always null here. */
export type MirrorDelivery = DeliveryRow;
export type MirrorDeliveryLine = DeliveryLineRow;

/** An order as the server returned it, or as confirmed on the phone. */
export type LocalOrder = OrderDetail & { deleted_at: string | null };

export interface OutboxEntry {
    seq?: number;
    uuid: string;
    method: 'PUT' | 'DELETE';
    path: string;
    body: unknown;
    attempts: number;
    lastError: string | null;
    createdAt: number;
    /** Epoch ms before which the automatic retry waits (triggers such as "online" ignore it). */
    nextAttemptAt: number;
}

export interface MetaRow {
    key: string;
    value: unknown;
}

export class UserDb extends Dexie {
    clients!: Table<MirrorClient, string>;
    articles!: Table<MirrorArticle, number>;
    paytypes!: Table<Paytype, number>;
    orders!: Table<LocalOrder, string>;
    outbox!: Table<OutboxEntry, number>;
    meta!: Table<MetaRow, string>;
    deliveries!: Table<MirrorDelivery, number>;
    delivery_lines!: Table<MirrorDeliveryLine, number>;

    constructor(public readonly userId: number) {
        super(userDbName(userId));
        this.version(1).stores({
            clients: 'key, id, uuid',
            articles: 'id',
            paytypes: 'id',
            orders: 'uuid',
            outbox: '++seq, uuid',
            meta: 'key',
        });
        // The whole Evoliz BL history (sales history and report).
        this.version(2).stores({
            deliveries: 'id, client_id, date',
            delivery_lines: 'id, delivery_id',
        });
        // Rows stored under the installation's older field names are read under the current ones (legacy.ts); no schema change.
        this.clients.hook('reading', (row) => readClient(row));
        this.articles.hook('reading', (row) => readArticle(row));
        this.orders.hook('reading', (row) => readOrder(row));
    }

    async getMeta<T>(key: string): Promise<T | undefined> {
        return (await this.meta.get(key))?.value as T | undefined;
    }

    async setMeta(key: string, value: unknown): Promise<void> {
        await this.meta.put({ key, value });
    }
}

export const userDbName = (userId: number): string => `${storage.userDbPrefix}${userId}`;

/** Props shared by every Inertia page, kept for pages rendered offline. */
export interface SessionRecord {
    key: 'current';
    user: AuthUser;
    appVersion: string;
    turnstileSiteKey: string | null;
    /** Absent in sessions stored before the test-mode flag existed. */
    evolizWritesEnabled?: boolean;
    /** Absent in sessions stored before SMS existed. */
    smsEnabled?: boolean;
    /** Absent in sessions stored before file mode existed. */
    syncProvider?: SyncProvider;
    /** The optional client field; absent in sessions stored by an older version (the search then reads it). */
    clientExtraField?: ClientExtraField | null;
    /** The installation's wording; absent in sessions stored before it existed (lang/fr.json as it is). */
    wording?: Wording | null;
    /** Variants and the "new article" button; absent in sessions stored before it existed (lib/catalog.ts). */
    catalog?: CatalogProp | null;
    /** Inertia asset version of the last online page. */
    version: string | null;
}

class SessionDb extends Dexie {
    session!: Table<SessionRecord, string>;

    constructor() {
        super(storage.sessionDb);
        this.version(1).stores({ session: 'key' });
    }
}

let sessionDb: SessionDb | null = null;
const sessions = (): SessionDb => (sessionDb ??= new SessionDb());

export async function readSession(): Promise<SessionRecord | null> {
    try {
        return (await sessions().session.get('current')) ?? null;
    } catch {
        return null;
    }
}

export async function writeSession(record: Omit<SessionRecord, 'key'>): Promise<void> {
    await sessions().session.put({ key: 'current', ...record });
}

export async function clearSession(): Promise<void> {
    await sessions().session.delete('current');
}

const open = new Map<number, UserDb>();

export function userDb(userId: number): UserDb {
    let db = open.get(userId);
    if (!db) {
        db = new UserDb(userId);
        open.set(userId, db);
    }
    return db;
}

/** Deletes a user's local data (logout). */
export async function deleteUserDb(userId: number): Promise<void> {
    open.get(userId)?.close();
    open.delete(userId);
    await Dexie.delete(userDbName(userId));
}
