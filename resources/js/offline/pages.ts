import type { Page } from '@inertiajs/core';

import type { LocalOrder, SessionRecord, UserDb } from './db';
import { HISTORY_SYNCED_AT } from './mirror';
import { t } from '@/lib/i18n';
import { aggregateSales, salesPresets, type SalesFilters, type SalesInput } from '@/lib/salesReport';
import type { OrderDetail, Paytype } from '@/types';

/**
 * Inertia pages built on the phone from local data, for when the server
 * cannot be reached (or does not know the order yet). The routes needed to
 * take an order work offline, and so does the sales report (computed from
 * the phone's copy of the BLs); any other route shows the offline page.
 */

export type LocalRoute =
    | { name: 'home' }
    | { name: 'orders' }
    | { name: 'create'; from: string | null }
    | { name: 'show'; uuid: string }
    | { name: 'edit'; uuid: string }
    | { name: 'reports' }
    | { name: 'sales' }
    | { name: 'unavailable' };

const UUID = '[0-9a-fA-F-]{36}';

export function matchRoute(url: URL): LocalRoute {
    const path = url.pathname.replace(/\/+$/, '') || '/';
    if (path === '/') return { name: 'home' };
    if (path === '/orders') return { name: 'orders' };
    if (path === '/orders/new') return { name: 'create', from: url.searchParams.get('from') };
    if (path === '/reports') return { name: 'reports' };
    if (path === '/reports/sales') return { name: 'sales' };

    const show = path.match(new RegExp(`^/orders/(${UUID})$`));
    if (show) return { name: 'show', uuid: show[1].toLowerCase() };

    const edit = path.match(new RegExp(`^/orders/(${UUID})/edit$`));
    if (edit) return { name: 'edit', uuid: edit[1].toLowerCase() };

    return { name: 'unavailable' };
}

/** Pending orders (or ones only the phone has) can still be changed. */
export const isEditableLocally = (order: OrderDetail): boolean => !order.deleted_at && (order.status === 'local' || order.status === 'pending');

function sharedProps(session: SessionRecord): Page['props'] {
    return {
        auth: { user: session.user },
        flash: { success: null, error: null, password: null },
        appVersion: session.appVersion,
        turnstileSiteKey: session.turnstileSiteKey,
        evolizWritesEnabled: session.evolizWritesEnabled ?? true,
        smsEnabled: session.smsEnabled ?? false,
        syncProvider: session.syncProvider ?? 'evoliz',
        // A session older than the setting kept the field searched; it stays shown too, under a neutral label.
        clientExtraField: session.clientExtraField === undefined ? { label: t('Client field'), searchable: true } : session.clientExtraField,
        wording: session.wording ?? null,
        catalog: session.catalog ?? { new_article: true, variants: [] },
        errors: {},
        // Lets pages know they were rendered from the phone's data.
        offline: true,
    };
}

async function paytypes(db: UserDb): Promise<Paytype[]> {
    const rows = await db.paytypes.toArray();
    return rows.sort((a, b) => a.label.localeCompare(b.label, 'fr'));
}

async function visibleOrder(db: UserDb, uuid: string): Promise<LocalOrder | null> {
    const order = await db.orders.get(uuid);
    return order && !order.deleted_at ? order : null;
}

/** The report's filters from its URL, with the server's defaults (SalesReport, ReportController::salesFilters). */
export function salesFilters(url: URL, today = new Date()): SalesFilters {
    const month = salesPresets(today)[2];
    const date = (key: string, fallback: string): string => {
        const value = url.searchParams.get(key);
        return value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : fallback;
    };
    const flag = (key: string): boolean => !['0', 'false'].includes(url.searchParams.get(key) ?? '1');
    const from = date('from', month.from);
    const to = date('to', month.to);
    const seller = Number(url.searchParams.get('seller'));
    return {
        from: from <= to ? from : to,
        to: from <= to ? to : from,
        delivered: flag('delivered'),
        invoiced: flag('invoiced'),
        seller_id: Number.isInteger(seller) && seller > 0 ? seller : null,
    };
}

/** "Ventes" from the phone's copy: the BLs of the period with their lines, the articles' category and variant, the clients' postcode. */
async function salesProps(db: UserDb, url: URL, session: SessionRecord): Promise<Record<string, unknown>> {
    const filters = salesFilters(url);
    const deliveries = await db.deliveries.where('date').between(filters.from, filters.to, true, true).toArray();
    const lines = deliveries.length ? await db.delivery_lines.where('delivery_id').anyOf(deliveries.map((delivery) => delivery.id)).toArray() : [];
    const articles: SalesInput['articles'] = {};
    for (const article of await db.articles.toArray()) articles[String(article.id)] = { reference: article.reference, category: article.category ?? null, variant: article.variant ?? null };
    const clients: SalesInput['clients'] = {};
    for (const client of await db.clients.toArray()) if (client.id) clients[String(client.id)] = { name: client.company_name, postcode: client.postcode ?? null };

    const sellers = new Map<number, string>();
    await db.deliveries.each((delivery) => {
        if (delivery.seller_id !== null) sellers.set(delivery.seller_id, delivery.seller);
    });
    const syncedAt = await db.getMeta<string>(HISTORY_SYNCED_AT);

    return {
        filters,
        presets: salesPresets(),
        sellers: [...sellers.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name, 'fr')),
        syncedAt: syncedAt ?? null,
        historyComplete: syncedAt !== undefined,
        report: aggregateSales({
            deliveries: deliveries.map((delivery) => ({ ...delivery, number: delivery.document_number })),
            lines: lines.sort((a, b) => a.delivery_id - b.delivery_id || a.position - b.position),
            articles,
            clients,
            // The profile's variants as the last online page had them (SalesReport::variants()).
            variants: (session.catalog?.variants ?? []).map((variant) => ({ value: variant.value, label: variant.label, counts: variant.counts, own_tab: variant.own_tab === true })),
            filters,
        }),
    };
}

export async function buildLocalPage(url: URL, db: UserDb, session: SessionRecord): Promise<Page> {
    const route = matchRoute(url);
    const page = (component: string, props: Record<string, unknown> = {}): Page =>
        ({
            component,
            props: { ...sharedProps(session), ...props },
            url: url.pathname + url.search,
            version: session.version,
            clearHistory: false,
            encryptHistory: false,
            flash: {},
        }) as unknown as Page;

    switch (route.name) {
        case 'home':
            return page('Home', { badges: null });
        case 'orders':
            return page('Orders/Index');
        case 'create': {
            const template = route.from ? await visibleOrder(db, route.from) : null;
            return page('Orders/Wizard', { order: null, template, paytypes: await paytypes(db) });
        }
        case 'show': {
            const order = await visibleOrder(db, route.uuid);
            return order ? page('Orders/Show', { order }) : page('Offline/Unavailable', { reason: 'order' });
        }
        case 'edit': {
            const order = await visibleOrder(db, route.uuid);
            return order && isEditableLocally(order)
                ? page('Orders/Wizard', { order, template: null, paytypes: await paytypes(db) })
                : page('Offline/Unavailable', { reason: 'order' });
        }
        case 'reports':
            return page('Reports/Index');
        case 'sales':
            // File mode: the phone has no copy of the sales (no BLs). A session older than the catalogue settings does not
            // know which variants count: figures could differ from the server's. A notice in both cases, never figures
            // that read as fact; the next online page stores the catalogue.
            return session.syncProvider === 'file' || session.catalog?.variants === undefined
                ? page('Offline/Unavailable', { reason: 'sales' })
                : page('Reports/Sales', await salesProps(db, url, session));
        default:
            return page('Offline/Unavailable', { reason: 'route' });
    }
}
