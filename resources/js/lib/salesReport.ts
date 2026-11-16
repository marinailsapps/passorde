/**
 * The sales report's aggregation on the phone (offline), line for line the
 * same as App\Domain\Reports\SalesReport::aggregate(); both are pinned by
 * tests/Fixtures/sales-report-cases.json: change the three together.
 * See the PHP class for the rules (quantities, BL totals vs line totals, counts).
 */

export const GROUPINGS = ['category', 'article', 'client', 'month', 'department', 'variant'] as const;
export type Grouping = (typeof GROUPINGS)[number];

const BY_DELIVERY: Grouping[] = ['client', 'month', 'department'];

export const NO_ARTICLE = '~none';
export const MATERIAL = '~material';
export const OTHER = '~other';
export const UNCLASSIFIED = '~unclassified';
export const UNKNOWN = '~unknown';

/** The profile's variants (SalesReport::variants(); offline, the session's catalog prop). */
export interface SalesVariant {
    value: string;
    label: string;
    counts: boolean;
    own_tab: boolean;
}

export interface SalesFilters {
    from: string;
    to: string;
    delivered: boolean;
    invoiced: boolean;
    seller_id: number | null;
}

export interface SalesInput {
    deliveries: {
        id: number;
        number: string;
        date: string;
        client_id: number | null;
        client_name: string | null;
        invoiced: boolean;
        seller_id: number | null;
        total_ht: string;
        total_vat: string;
        total_ttc: string;
    }[];
    lines: { delivery_id: number; article_id: number | null; reference: string | null; quantity: string; total_ht: string; vat_rate: string | null; is_free: boolean }[];
    articles: Record<string, { reference: string | null; category: string | null; variant: string | null }>;
    clients: Record<string, { name: string; postcode: string | null }>;
    /** Absent in inputs stored by an older version: every variant then counts and groups by category. */
    variants?: SalesVariant[];
    filters: SalesFilters;
}

export interface SalesGroupRow {
    key: string;
    /** null for the special keys ("~none"…), translated as `sales.<key>`. */
    label: string | null;
    count: number;
    quantity: string;
    free_quantity: string;
    ht: string;
    ttc: string;
    deliveries: number[];
}

export interface SalesDelivery {
    id: number;
    number: string;
    date: string;
    client: string;
    invoiced: boolean;
    ht: string;
    ttc: string;
}

export interface SalesResult {
    cards: { count: number; quantity: string; free_quantity: string; ht: string; vat: string; ttc: string };
    groups: Record<Grouping, SalesGroupRow[]>;
    deliveries: SalesDelivery[];
}

/** PHP's round(…, 0, PHP_ROUND_HALF_UP): halves away from zero. */
const roundHalfUp = (value: number): number => (value < 0 ? -Math.round(-value) : Math.round(value));
const toCents = (value: string | number): number => roundHalfUp(Number(value) * 100);
const money = (cents: number): string => (cents / 100).toFixed(2);
const compare = (a: string, b: string): number => (a < b ? -1 : a > b ? 1 : 0);

interface Accumulator {
    key: string;
    label: string | null;
    deliveries: number[];
    quantity: number;
    free_quantity: number;
    ht: number;
    ttc: number;
    htByRate: Map<string, number>;
}

function row(rows: Map<string, Accumulator>, key: string, label: string | null): Accumulator {
    let found = rows.get(key);
    if (!found) {
        found = { key, label, deliveries: [], quantity: 0, free_quantity: 0, ht: 0, ttc: 0, htByRate: new Map() };
        rows.set(key, found);
    }
    return found;
}

function addDelivery(target: Accumulator, id: number): void {
    if (target.deliveries.at(-1) !== id) target.deliveries.push(id);
}

type Article = SalesInput['articles'][string];
type Line = SalesInput['lines'][number];

type Variants = Map<string, SalesVariant>;

function quantities(line: Line, article: Article | null, variants: Variants): [number, number] {
    if (article === null || variants.get(String(article.variant))?.counts === false) return [0, 0];
    const quantity = toCents(line.quantity);
    return [quantity, line.is_free ? quantity : 0];
}

function lineKey(grouping: Grouping, line: Line, article: Article | null, variants: Variants): [string, string | null] {
    if (article === null) {
        return grouping === 'article' && line.reference !== null && line.reference !== '' ? [`ref:${line.reference}`, line.reference] : [NO_ARTICLE, null];
    }
    if (grouping === 'article') return [`id:${line.article_id}`, article.reference];
    const variant = article.variant !== null ? variants.get(article.variant) : undefined;
    if (grouping === 'variant') return article.variant !== null ? [article.variant, variant?.label ?? article.variant] : [UNCLASSIFIED, null];
    if (variant?.own_tab === true) return [MATERIAL, null];
    if (variant !== undefined && !variant.counts) return [OTHER, null];
    return article.category !== null ? [article.category, article.category] : [UNCLASSIFIED, null];
}

function finishRow(acc: Accumulator, fromLines: boolean): SalesGroupRow {
    let ttc = acc.ttc;
    if (fromLines) {
        let vat = 0;
        for (const rate of [...acc.htByRate.keys()].sort(compare)) vat += roundHalfUp(((acc.htByRate.get(rate) ?? 0) * toCents(rate)) / 10000);
        ttc = acc.ht + vat;
    }
    return {
        key: acc.key,
        label: acc.label,
        count: acc.deliveries.length,
        quantity: money(acc.quantity),
        free_quantity: money(acc.free_quantity),
        ht: money(acc.ht),
        ttc: money(ttc),
        deliveries: acc.deliveries,
    };
}

export function aggregateSales(input: SalesInput): SalesResult {
    const { filters, articles, clients } = input;
    const variants: Variants = new Map((input.variants ?? []).map((variant) => [variant.value, variant]));

    const deliveries = input.deliveries
        .filter((d) => d.date >= filters.from && d.date <= filters.to && (d.invoiced ? filters.invoiced : filters.delivered) && (filters.seller_id === null || d.seller_id === filters.seller_id))
        .sort((a, b) => compare(b.date, a.date) || compare(b.number, a.number) || b.id - a.id);

    const linesOf = new Map<number, Line[]>();
    for (const line of input.lines) linesOf.set(line.delivery_id, [...(linesOf.get(line.delivery_id) ?? []), line]);

    const cards = { count: 0, quantity: 0, free_quantity: 0, ht: 0, vat: 0, ttc: 0 };
    const groups = new Map<Grouping, Map<string, Accumulator>>(GROUPINGS.map((g) => [g, new Map()]));
    const listed: SalesDelivery[] = [];

    for (const delivery of deliveries) {
        const client = delivery.client_id !== null ? (clients[String(delivery.client_id)] ?? null) : null;
        const clientLabel = client?.name ?? delivery.client_name ?? '—';
        const ht = toCents(delivery.total_ht);
        const ttc = toCents(delivery.total_ttc);

        cards.count++;
        cards.ht += ht;
        cards.vat += toCents(delivery.total_vat);
        cards.ttc += ttc;

        let deliveryQuantity = 0;
        let deliveryFree = 0;
        for (const line of linesOf.get(delivery.id) ?? []) {
            const article = line.article_id !== null ? (articles[String(line.article_id)] ?? null) : null;
            const [lineQuantity, lineFree] = quantities(line, article, variants);
            deliveryQuantity += lineQuantity;
            deliveryFree += lineFree;

            for (const grouping of ['category', 'article', 'variant'] as Grouping[]) {
                const [key, label] = lineKey(grouping, line, article, variants);
                const target = row(groups.get(grouping)!, key, label);
                addDelivery(target, delivery.id);
                target.quantity += lineQuantity;
                target.free_quantity += lineFree;
                target.ht += toCents(line.total_ht);
                const rate = line.vat_rate === null ? '0.00' : Number(line.vat_rate).toFixed(2);
                target.htByRate.set(rate, (target.htByRate.get(rate) ?? 0) + toCents(line.total_ht));
            }
        }
        cards.quantity += deliveryQuantity;
        cards.free_quantity += deliveryFree;

        const postcode = client?.postcode ?? '';
        const byDelivery: [Grouping, string, string | null][] = [
            ['client', delivery.client_id !== null ? `id:${delivery.client_id}` : `name:${clientLabel}`, clientLabel],
            ['month', delivery.date.slice(0, 7), delivery.date.slice(0, 7)],
            /^\d{5}$/.test(postcode) ? ['department', postcode.slice(0, 2), postcode.slice(0, 2)] : ['department', UNKNOWN, null],
        ];
        for (const [grouping, key, label] of byDelivery) {
            const target = row(groups.get(grouping)!, key, label);
            addDelivery(target, delivery.id);
            target.quantity += deliveryQuantity;
            target.free_quantity += deliveryFree;
            target.ht += ht;
            target.ttc += ttc;
        }

        listed.push({ id: delivery.id, number: delivery.number, date: delivery.date, client: clientLabel, invoiced: delivery.invoiced, ht: money(ht), ttc: money(ttc) });
    }

    const result = {} as Record<Grouping, SalesGroupRow[]>;
    for (const grouping of GROUPINGS) {
        const rows = [...groups.get(grouping)!.values()].map((acc) => finishRow(acc, !BY_DELIVERY.includes(grouping)));
        rows.sort(grouping === 'month' ? (a, b) => compare(a.key, b.key) : (a, b) => toCents(b.ht) - toCents(a.ht) || compare(a.key, b.key));
        result[grouping] = rows;
    }

    return {
        cards: { count: cards.count, quantity: money(cards.quantity), free_quantity: money(cards.free_quantity), ht: money(cards.ht), vat: money(cards.vat), ttc: money(cards.ttc) },
        groups: result,
        deliveries: listed,
    };
}

/** The quick periods for today in Europe/Paris, like SalesReport::presets(). */
export function salesPresets(today: Date = new Date()): { key: string; from: string; to: string }[] {
    const ymd = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris', year: 'numeric', month: '2-digit', day: '2-digit' }).format(today);
    const day = new Date(`${ymd}T12:00:00Z`);
    const iso = (date: Date): string => date.toISOString().slice(0, 10);
    const addDays = (date: Date, days: number): Date => new Date(date.getTime() + days * 86_400_000);
    const monday = addDays(day, -((day.getUTCDay() + 6) % 7));
    const quarterStart = new Date(Date.UTC(day.getUTCFullYear(), Math.floor(day.getUTCMonth() / 3) * 3, 1, 12));

    return [
        { key: 'this_week', from: iso(monday), to: ymd },
        { key: 'last_week', from: iso(addDays(monday, -7)), to: iso(addDays(monday, -1)) },
        { key: 'this_month', from: `${ymd.slice(0, 7)}-01`, to: ymd },
        { key: 'this_quarter', from: iso(quarterStart), to: ymd },
        { key: 'this_year', from: `${ymd.slice(0, 4)}-01-01`, to: ymd },
    ];
}
