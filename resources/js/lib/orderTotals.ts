/**
 * Order totals in integer cents; the same rules as app/Domain/Orders/OrderTotals.php.
 * Line HT = quantity × unit price rounded to the cent; VAT once per rate on that
 * rate's HT sum, rounded half up (tests/Feature/Orders/OrderTotalsTest.php).
 */
export interface TotalsLine {
    quantity: number | string;
    unit_price_ht: number | string;
    vat_rate: number | string;
}

export interface VatRow {
    rate: number;
    baseCents: number;
    vatCents: number;
}

export interface Totals {
    quantity: number;
    htCents: number;
    vatCents: number;
    ttcCents: number;
    byRate: VatRow[];
}

export function toCents(value: number | string): number {
    const number = Number(value);
    return Number.isFinite(number) ? Math.round(number * 100) : 0;
}

export function lineHtCents(quantity: number | string, unitPrice: number | string): number {
    // Both have at most 2 decimals: the product of the cents is in 1/10000 €.
    return Math.round((toCents(quantity) * toCents(unitPrice)) / 100);
}

export function computeTotals(lines: TotalsLine[]): Totals {
    const htByRate = new Map<number, number>();
    let quantityCents = 0;

    for (const line of lines) {
        quantityCents += toCents(line.quantity);
        const rate = toCents(line.vat_rate);
        htByRate.set(rate, (htByRate.get(rate) ?? 0) + lineHtCents(line.quantity, line.unit_price_ht));
    }

    const byRate: VatRow[] = [...htByRate.entries()]
        .sort(([a], [b]) => a - b)
        .map(([rate, baseCents]) => ({ rate: rate / 100, baseCents, vatCents: Math.round((baseCents * rate) / 10000) }));

    const htCents = byRate.reduce((sum, row) => sum + row.baseCents, 0);
    const vatCents = byRate.reduce((sum, row) => sum + row.vatCents, 0);

    return { quantity: quantityCents / 100, htCents, vatCents, ttcCents: htCents + vatCents, byRate };
}

export function fromCents(cents: number): number {
    return cents / 100;
}
