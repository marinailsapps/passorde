import type { DeliveryLineRow, DeliveryRow, HistoryEntry, HistoryStatus, OrderDetail, RecentOrder } from '@/types';

/**
 * A client's sales history built on the phone, like App\Domain\Sales\SalesHistory
 * on the server (docs/API.md "Sales history"): the mirrored Evoliz BLs plus the
 * phone's own orders not in Evoliz yet. Other users' pending orders are only
 * on the server, so the offline history shows the BLs and this user's orders.
 * An exported order counts as delivered, dated by its export, until a mirrored BL
 * carries its uuid: then only the BL is listed, never both (any status).
 */

type HistoryOrder = OrderDetail & { deleted_at?: string | null };

const parisDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris', year: 'numeric', month: '2-digit', day: '2-digit' });

export function fromDelivery(delivery: DeliveryRow, lines: DeliveryLineRow[]): HistoryEntry {
    return {
        key: `bl:${delivery.id}`,
        date: delivery.date,
        delivery_number: delivery.document_number,
        order_number: delivery.order_number,
        order_uuid: delivery.order_uuid,
        status: delivery.invoiced ? 'invoiced' : 'delivered',
        seller: delivery.seller,
        comment: delivery.comment,
        lines: [...lines]
            .sort((a, b) => a.position - b.position)
            .map((line) => ({
                article_id: line.article_id,
                reference: line.reference,
                designation: line.designation,
                quantity: line.quantity,
                unit_price_ht: line.unit_price_ht,
                vat_rate: line.vat_rate,
                is_free: line.is_free,
                total_ht: line.total_ht,
            })),
        totals: { ht: delivery.total_ht, vat: delivery.total_vat, ttc: delivery.total_ttc },
    };
}

export function fromOrder(order: HistoryOrder): HistoryEntry {
    const status: HistoryStatus = order.status === 'rejected' ? 'rejected' : order.status === 'sent' || order.exported_at ? 'delivered' : 'pending';
    const at = order.status === 'sent' && order.sent_at ? order.sent_at : (order.exported_at ?? order.created_at);
    return {
        key: `order:${order.uuid}`,
        date: parisDate.format(new Date(at)),
        delivery_number: order.evoliz_delivery_number,
        order_number: order.number || null,
        order_uuid: order.uuid,
        status,
        seller: order.author.name,
        comment: order.comment,
        lines: order.lines.map((line) => ({
            article_id: line.article_id,
            reference: line.reference,
            designation: line.designation,
            quantity: line.quantity,
            unit_price_ht: line.unit_price_ht,
            vat_rate: line.vat_rate,
            is_free: line.is_free,
            total_ht: line.total_ht,
        })),
        totals: { ht: order.totals.ht, vat: order.totals.vat, ttc: order.totals.ttc },
    };
}

/** Newest first; on the same day, the higher BL number, then the higher order number. */
export function compareEntries(a: HistoryEntry, b: HistoryEntry): number {
    for (const [x, y] of [
        [b.date, a.date],
        [b.delivery_number ?? '', a.delivery_number ?? ''],
        [b.order_number ?? '', a.order_number ?? ''],
    ]) {
        if (x !== y) return x < y ? -1 : 1;
    }
    return 0;
}

/** The client's entries: BLs of the client, and orders without a mirrored BL. */
export function historyEntries(deliveries: DeliveryRow[], lines: DeliveryLineRow[], orders: HistoryOrder[]): HistoryEntry[] {
    const byDelivery = new Map<number, DeliveryLineRow[]>();
    for (const line of lines) byDelivery.set(line.delivery_id, [...(byDelivery.get(line.delivery_id) ?? []), line]);
    const mirrored = new Set(deliveries.map((delivery) => delivery.order_uuid).filter((uuid): uuid is string => uuid !== null));

    return [
        ...deliveries.map((delivery) => fromDelivery(delivery, byDelivery.get(delivery.id) ?? [])),
        ...orders.filter((order) => !order.deleted_at && !mirrored.has(order.uuid)).map(fromOrder),
    ].sort(compareEntries);
}

interface ArticleInfo {
    is_active: boolean;
    is_stocked: boolean;
    stock_qty: string | null;
    reserved_qty: string;
    unit_price_ht: string;
}

/** "Copier une commande précédente" offline: the history's last 3 entries in the shape of the API. */
export function recentFromHistory(entries: HistoryEntry[], articles: Map<number, ArticleInfo>, orders: HistoryOrder[]): RecentOrder[] {
    const byUuid = new Map(orders.map((order) => [order.uuid, order]));
    return entries.slice(0, 3).map((entry) => {
        const order = entry.order_uuid ? byUuid.get(entry.order_uuid) : undefined;
        return {
            uuid: entry.order_uuid ?? entry.key,
            number: entry.order_number ?? entry.delivery_number ?? '',
            delivery_number: entry.delivery_number,
            status: order ? (order.status === 'local' ? 'pending' : (order.status as RecentOrder['status'])) : 'sent',
            history_status: entry.status,
            created_at: order?.created_at ?? `${entry.date}T00:00:00`,
            author: entry.seller,
            total_ttc: entry.totals.ttc,
            lines: entry.lines.map((line, position) => {
                const article = line.article_id !== null ? articles.get(line.article_id) : undefined;
                return {
                    ...line,
                    position,
                    vat_rate: line.vat_rate ?? '0.00',
                    stock: article ? { is_stocked: article.is_stocked, stock_qty: article.stock_qty, reserved_qty: article.reserved_qty } : null,
                    catalog_unit_price_ht: article?.unit_price_ht ?? null,
                    available: article?.is_active ?? false,
                };
            }),
        };
    });
}
