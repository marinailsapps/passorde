import type { LocalOrder, UserDb } from './db';
import { hasCorrection, pendingChange, type ClientCorrection } from '@/lib/clientCorrection';
import { t } from '@/lib/i18n';
import type { ApiLine, LineGroup } from '@/lib/orderLines';
import { computeTotals, fromCents, lineHtCents } from '@/lib/orderTotals';
import type { AuthUser, ClientData, ClientSelection, NewClientData, OrderDetail, OrderLineData, Paytype } from '@/types';

/**
 * Orders confirmed on the phone: the local copy and the outbox entries that
 * carry them to the server (see outbox.ts).
 */

export interface OrderBody {
    client_id?: number;
    client?: NewClientData;
    client_changes?: ClientCorrection;
    lines: ApiLine[];
    paytype_id: number | null;
    comment: string;
    duplicated_from: string | null;
}

const money = (cents: number): string => fromCents(cents).toFixed(2);
const blankToNull = (value: string | null | undefined): string | null => (value === undefined || value === null || value.trim() === '' ? null : value.trim());

/** True for an order only the phone knows (no number yet). */
export const isLocalOnly = (order: Pick<OrderDetail, 'number'>): boolean => order.number === '';

/** The client as stored on a local order: a typed client has id 0 until the server creates it. */
export function clientOf(selection: ClientSelection): ClientData {
    if (selection.kind === 'existing') return selection.client;
    const typed = selection.client;
    return {
        id: 0,
        uuid: typed.uuid,
        company_name: typed.company_name,
        contact_name: blankToNull(typed.contact_name),
        address_line: blankToNull(typed.address_line),
        postcode: blankToNull(typed.postcode),
        town: blankToNull(typed.town),
        phone: blankToNull(typed.phone),
        email: blankToNull(typed.email),
        extra_field: blankToNull(typed.extra_field),
        linked: false,
    };
}

/** The selection to edit a local order again: a client with id 0 is still a typed one. */
export function selectionOf(client: ClientData): ClientSelection {
    if (client.id !== 0 || !client.uuid) return { kind: 'existing', client };
    return {
        kind: 'new',
        client: {
            uuid: client.uuid,
            company_name: client.company_name,
            contact_name: client.contact_name ?? '',
            address_line: client.address_line ?? '',
            postcode: client.postcode ?? '',
            town: client.town ?? '',
            phone: client.phone ?? '',
            email: client.email ?? '',
            extra_field: client.extra_field ?? '',
        },
    };
}

export interface DraftInput {
    uuid: string;
    previous: OrderDetail | null;
    selection: ClientSelection;
    groups: LineGroup[];
    lines: ApiLine[];
    paytype: Paytype | null;
    comment: string;
    author: AuthUser;
    now?: Date;
}

/** The order as the phone shows it until the server answers, in the API's shape. */
export function localOrderFromDraft(input: DraftInput): LocalOrder {
    const now = (input.now ?? new Date()).toISOString();
    const articles = new Map(input.groups.map((group) => [group.article.id, group.article]));

    const lines: OrderLineData[] = input.lines.map((line) => {
        const article = articles.get(line.article_id);
        const price = line.is_free ? 0 : line.unit_price_ht;
        return {
            position: line.position,
            article_id: line.article_id,
            reference: article?.reference ?? null,
            designation: article?.designation ?? '',
            note: line.note ?? null,
            quantity: line.quantity.toFixed(2),
            unit_price_ht: Number(price).toFixed(2),
            vat_rate: Number(article?.vat_rate ?? 0).toFixed(2),
            is_free: line.is_free,
            total_ht: money(lineHtCents(line.quantity, price)),
            stock: article ? { is_stocked: article.is_stocked, stock_qty: article.stock_qty, reserved_qty: article.reserved_qty } : null,
            catalog_unit_price_ht: article?.unit_price_ht ?? null,
        };
    });

    const totals = computeTotals(lines);
    const previous = input.previous;
    const onServer = previous !== null && previous.number !== '';

    return {
        uuid: input.uuid,
        number: onServer ? previous.number : '',
        status: onServer ? previous.status : 'local',
        display_status: onServer ? previous.display_status : 'local',
        status_label: onServer ? previous.status_label : t('Local draft'),
        created_at: previous?.created_at ?? now,
        updated_at: now,
        approved_at: null,
        approved_by: null,
        sent_at: null,
        exported_at: null,
        author: previous?.author ?? { id: input.author.id, name: input.author.name },
        client: clientOf(input.selection),
        client_change:
            input.selection.kind === 'existing' && hasCorrection(input.selection.changes)
                ? pendingChange(input.selection.client, input.selection.changes)
                : null,
        paytype: input.paytype,
        comment: blankToNull(input.comment),
        lines,
        totals: {
            quantity: totals.quantity.toFixed(2),
            ht: money(totals.htCents),
            vat: money(totals.vatCents),
            ttc: money(totals.ttcCents),
            vat_by_rate: totals.byRate.map((row) => ({ rate: row.rate.toFixed(2), base_ht: money(row.baseCents), vat: money(row.vatCents) })),
        },
        rejected_reason: null,
        evoliz_delivery_number: null,
        can_edit: true,
        can_delete: previous === null ? true : previous.can_delete,
        evoliz_error: null,
        evoliz_warning: null,
        activity: null,
        can_approve: false,
        can_reject: false,
        can_retry: false,
        stock_conflicts: null,
        deleted_at: null,
        local_state: 'queued',
        local_error: null,
        possible_duplicate: null,
    };
}

/** A plain copy: IndexedDB cannot store Vue's reactive proxies (DataCloneError). */
const plain = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

/** "Confirmer": keep the order on the phone and queue its PUT. */
export async function queueSave(db: UserDb, reactiveOrder: LocalOrder, reactiveBody: OrderBody, now = Date.now()): Promise<void> {
    const order = plain(reactiveOrder);
    const body = plain(reactiveBody);
    await db.transaction('rw', db.orders, db.outbox, db.clients, async () => {
        await db.orders.put({ ...order, local_state: 'queued', local_error: null });
        await db.outbox.add({ uuid: order.uuid, method: 'PUT', path: `/api/orders/${order.uuid}`, body, attempts: 0, lastError: null, createdAt: now, nextAttemptAt: 0 });

        // A client typed on the phone shows in the local directory straight away.
        if (order.client.id === 0 && order.client.uuid) {
            await db.clients.put({ ...order.client, key: `uuid:${order.client.uuid}`, is_active: true, updated_at: null, pending: true });
        }
    });
}

/**
 * Deletes a pending order. One the server never received (and never tried
 * to) simply disappears; otherwise the DELETE goes through the outbox.
 */
export async function queueDelete(db: UserDb, uuid: string, now = Date.now()): Promise<'removed' | 'queued'> {
    return db.transaction('rw', db.orders, db.outbox, async () => {
        const order = await db.orders.get(uuid);
        const entries = await db.outbox.where('uuid').equals(uuid).toArray();
        const neverSent = order !== undefined && isLocalOnly(order) && entries.every((entry) => entry.attempts === 0);

        if (neverSent) {
            await db.outbox.where('uuid').equals(uuid).delete();
            await db.orders.delete(uuid);
            return 'removed';
        }

        if (order) await db.orders.put({ ...order, deleted_at: new Date(now).toISOString(), local_state: 'queued', local_error: null });
        await db.outbox.add({ uuid, method: 'DELETE', path: `/api/orders/${uuid}`, body: null, attempts: 0, lastError: null, createdAt: now, nextAttemptAt: 0 });
        return 'queued';
    });
}

/** Clears the "Non envoyée" notice of an order the server has in another state. */
export async function dismissError(db: UserDb, uuid: string): Promise<void> {
    const order = await db.orders.get(uuid);
    if (!order) return;
    // Someone else's order (the operator's refused correction) is not mirrored here.
    if (order.author.id !== db.userId) await db.orders.delete(uuid);
    else await db.orders.put({ ...order, local_state: 'synced', local_error: null });
}
