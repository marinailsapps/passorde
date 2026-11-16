/**
 * Rows of a stock report tab as listed on screen: inactive articles (see
 * StockReport.php) are hidden unless asked for, never in "À classer" and
 * never in the broken / gift tabs.
 */
export function visibleRows<T extends object>(tab: { key: string; kind: string; rows: T[] }, hideInactive: boolean): T[] {
    if (tab.kind !== 'stock' || !hideInactive || tab.key === 'a_classer') return tab.rows;
    return tab.rows.filter((row) => !('active' in row) || row.active !== false);
}
