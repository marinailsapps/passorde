import { computed, reactive } from 'vue';

import { computeTotals } from './orderTotals';
import type { ArticleHit, OrderLineData } from '@/types';

/**
 * The order form edits "groups": one paid line of an article plus its optional
 * free lines (same article at 0 €), shown nested under it.
 * On save each group expands to one or two API lines, free line right after.
 */
export interface LineArticle {
    id: number;
    reference: string | null;
    designation: string;
    unit_price_ht: string;
    vat_rate: string;
    is_stocked: boolean;
    stock_qty: string | null;
    reserved_qty: string;
    /** When the stock figures come from the phone's copy: its sync time (ISO). */
    stock_as_of?: string | null;
}

export interface LineGroup {
    key: string;
    article: LineArticle;
    quantity: number;
    unitPrice: string;
    freeQuantity: number;
    /** Comments on the paid line and on the free lines. Missing in drafts saved by an older version. */
    note?: string;
    freeNote?: string;
    /** False until the user types a price; used to flag 0 € catalogue prices. */
    priceEdited: boolean;
}

export interface ApiLine {
    article_id: number;
    quantity: number;
    unit_price_ht: number;
    is_free: boolean;
    position: number;
    note?: string;
}

let counter = 0;
const newKey = (): string => `g${Date.now().toString(36)}${(counter++).toString(36)}`;

export function articleFromLine(line: OrderLineData): LineArticle {
    return {
        id: line.article_id ?? 0,
        reference: line.reference,
        designation: line.designation,
        // The catalogue price, so the line can hint when its own (copied) price differs.
        unit_price_ht: line.catalog_unit_price_ht ?? line.unit_price_ht,
        vat_rate: line.vat_rate,
        is_stocked: line.stock?.is_stocked ?? false,
        stock_qty: line.stock?.stock_qty ?? null,
        reserved_qty: line.stock?.reserved_qty ?? '0',
    };
}

export function createLineEditor(initial: LineGroup[] = []) {
    const state = reactive({ groups: initial.map((group) => ({ ...group })) as LineGroup[] });

    const find = (key: string): LineGroup | undefined => state.groups.find((group) => group.key === key);

    /**
     * Adds an article, or one more of it when it is already in the order (that
     * line stays where it is). atTop: the new line goes first, right under the
     * search field, so the seller does not scroll after each article.
     */
    function addArticle(article: ArticleHit | LineArticle, quantity = 1, unitPrice?: string, atTop = false): LineGroup {
        const existing = state.groups.find((group) => group.article.id === article.id);
        if (existing) {
            existing.quantity += quantity;
            return existing;
        }

        const group: LineGroup = {
            key: newKey(),
            article: { ...article },
            quantity,
            unitPrice: unitPrice ?? article.unit_price_ht,
            freeQuantity: 0,
            priceEdited: unitPrice !== undefined,
        };
        if (atTop) state.groups.unshift(group);
        else state.groups.push(group);
        return group;
    }

    function addFree(key: string, quantity = 1): void {
        const group = find(key);
        if (group) group.freeQuantity += quantity;
    }

    function setQuantity(key: string, quantity: number): void {
        const group = find(key);
        if (group) group.quantity = Math.max(0, quantity);
    }

    function setFreeQuantity(key: string, quantity: number): void {
        const group = find(key);
        if (group) group.freeQuantity = Math.max(0, quantity);
    }

    function setNote(key: string, note: string, free = false): void {
        const group = find(key);
        if (group) group[free ? 'freeNote' : 'note'] = note;
    }

    function setPrice(key: string, price: string): void {
        const group = find(key);
        if (group) {
            group.unitPrice = price;
            group.priceEdited = true;
        }
    }

    /** Removing a paid line removes its free lines too. */
    function remove(key: string): void {
        state.groups = state.groups.filter((group) => group.key !== key);
    }

    function move(key: string, offset: -1 | 1): void {
        const index = state.groups.findIndex((group) => group.key === key);
        const target = index + offset;
        if (index < 0 || target < 0 || target >= state.groups.length) return;
        const [group] = state.groups.splice(index, 1);
        state.groups.splice(target, 0, group);
    }

    /** Appends API lines (a previous order): paid lines first make groups, free lines attach to them. */
    function appendLines(lines: OrderLineData[]): void {
        const sorted = [...lines].sort((a, b) => a.position - b.position);
        for (const line of sorted.filter((l) => !l.is_free && l.article_id !== null)) {
            const group = addArticle(articleFromLine(line), Number(line.quantity), line.unit_price_ht);
            if (line.note && !group.note) group.note = line.note;
        }
        for (const line of sorted.filter((l) => l.is_free && l.article_id !== null)) {
            const group = state.groups.find((g) => g.article.id === line.article_id);
            if (group) {
                group.freeQuantity += Number(line.quantity);
                if (line.note && !group.freeNote) group.freeNote = line.note;
            }
        }
    }

    function toApiLines(): ApiLine[] {
        const lines: ApiLine[] = [];
        for (const group of state.groups) {
            lines.push({
                article_id: group.article.id,
                quantity: group.quantity,
                unit_price_ht: Number(group.unitPrice) || 0,
                is_free: false,
                position: lines.length,
                note: group.note?.trim() || undefined,
            });
            if (group.freeQuantity > 0) {
                lines.push({
                    article_id: group.article.id,
                    quantity: group.freeQuantity,
                    unit_price_ht: 0,
                    is_free: true,
                    position: lines.length,
                    note: group.freeNote?.trim() || undefined,
                });
            }
        }
        return lines;
    }

    const totals = computed(() =>
        computeTotals(
            state.groups.flatMap((group) => [
                { quantity: group.quantity, unit_price_ht: group.unitPrice, vat_rate: group.article.vat_rate },
                { quantity: group.freeQuantity, unit_price_ht: 0, vat_rate: group.article.vat_rate },
            ]),
        ),
    );

    return { state, addArticle, addFree, setQuantity, setFreeQuantity, setNote, setPrice, remove, move, appendLines, toApiLines, totals };
}

export type LineEditor = ReturnType<typeof createLineEditor>;

/** Groups from saved order lines (edit or duplicate). */
export function groupsFromLines(lines: OrderLineData[]): LineGroup[] {
    const editor = createLineEditor();
    editor.appendLines(lines);
    return editor.state.groups.map((group) => ({ ...group, priceEdited: true }));
}
