<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SearchField from '@/components/SearchField.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { slug } from '@/lib/download';
import { useExcelExport } from '@/lib/excelExport';
import { formatAge, formatDate, formatNumber } from '@/lib/format';
import { isFileMode } from '@/lib/syncMode';
import { queryWords, words } from '@/lib/fuzzyMatch';
import { t, tc } from '@/lib/i18n';
import { visibleRows } from '@/lib/stockReport';

interface StockRow {
    article_id: number;
    reference: string;
    designation: string;
    category: string | null;
    variant: string | null;
    type_label: string | null;
    stock: string | null;
    reserved: string;
    broken: string;
    gifts: string;
    at_term: string | null;
    threshold: number | null;
    to_order: string | null;
    supplier: string | null;
    active: boolean;
}

interface EventRow {
    date: string;
    source?: 'order' | 'adjustment';
    order_number?: string | null;
    order_uuid?: string | null;
    client?: string | null;
    reference: string | null;
    designation: string;
    quantity: string;
    note: string | null;
    user?: string;
    evoliz_status?: 'pending' | 'sent' | 'failed' | 'disabled' | 'skipped' | null;
}

type Tab =
    | { key: string; label: string; kind: 'stock'; variant?: boolean; rows: StockRow[]; totals: Record<string, string> }
    | { key: string; label: string; kind: 'broken' | 'gifts'; variant?: boolean; rows: EventRow[]; totals: { quantity: string } };

const props = defineProps<{
    report: { period: { from: string; to: string }; synced_at: string | null; tabs: Tab[] };
}>();

/*
 * Tabs: the category groups first (the first one opens) or, with no categories, the one list
 * of articles; then the variants' own tabs, À CLASSER and the events.
 */
const tabs = computed(() => {
    const variants = props.report.tabs.filter((tab) => tab.variant === true);
    const others = props.report.tabs.filter((tab) => tab.variant !== true);
    const found = others.findIndex((tab) => tab.key === 'a_classer' || tab.kind !== 'stock');
    const after = found === -1 ? others.length : found;
    return [...others.slice(0, after), ...variants, ...others.slice(after)];
});
const active = ref(tabs.value[0]?.key ?? '');
const tab = computed(() => tabs.value.find((candidate) => candidate.key === active.value) ?? tabs.value[0]);
const isAdmin = computed(() => usePage().props.auth.user?.role === 'admin');

function toOrderCount(candidate: Tab): number {
    return candidate.kind === 'stock' ? candidate.rows.filter((row) => Number(row.to_order ?? 0) > 0).length : 0;
}

/*
 * Inactive articles (no stock, no order, Evoliz BL or journal entry for 12 months, no
 * threshold of their own: StockReport) are hidden by default, never in
 * "À classer". The totals stay those of the whole tab, hidden rows included.
 */
const hideInactive = ref(true);
function listed(candidate: Tab): (StockRow | EventRow)[] {
    return visibleRows<StockRow | EventRow>(candidate, hideInactive.value);
}
const hidden = computed(() => tab.value.rows.length - listed(tab.value).length);

/* Search within the tab: every typed word must start a word of the reference, designation or client. */
const search = ref('');
const shown = computed<(StockRow | EventRow)[]>(() => {
    const query = queryWords(search.value);
    const rows = listed(tab.value);
    if (query.length === 0) return rows;
    return rows.filter((row) => {
        const haystack = words([row.reference, row.designation, 'client' in row ? row.client : null, 'user' in row ? row.user : null].join(' '));
        return query.every((word) => haystack.some((candidate) => candidate.startsWith(word)));
    });
});
const stockRows = computed(() => (tab.value.kind === 'stock' ? (shown.value as StockRow[]) : []));
const eventRows = computed(() => (tab.value.kind !== 'stock' ? (shown.value as EventRow[]) : []));
watch(active, () => (search.value = ''));

/* À terme ≤ 0: red; ≤ seuil: amber. */
function level(row: StockRow): 'danger' | 'warning' | null {
    if (row.at_term === null) return null;
    const atTerm = Number(row.at_term);
    if (atTerm <= 0) return 'danger';
    if (row.threshold !== null && atTerm <= row.threshold) return 'warning';
    return null;
}
const levelRow = { danger: 'bg-red-50', warning: 'bg-amber-50' } as const;
const levelText = { danger: 'text-red-800', warning: 'text-amber-900' } as const;

/* Period: only for broken entries and gifts; the stock is always today's. */
const period = reactive({ ...props.report.period });
const loading = ref(false);
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());

function load(): void {
    if (!period.from || !period.to) return;
    router.get('/reports/stock', { from: period.from, to: period.to }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['report'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function preset(kind: 'year' | 'month' | 'days30'): void {
    const [year, month] = today.split('-');
    const start = new Date(`${today}T12:00:00Z`);
    start.setUTCDate(start.getUTCDate() - 30);
    period.from = { year: `${year}-01-01`, month: `${year}-${month}-01`, days30: start.toISOString().slice(0, 10) }[kind];
    period.to = today;
    load();
}
const presets = [
    { kind: 'year', label: 'This year' },
    { kind: 'month', label: 'This month' },
    { kind: 'days30', label: 'Last 30 days' },
] as const;
const excel = computed(() => `/reports/stock.xlsx?from=${props.report.period.from}&to=${props.report.period.to}`);

const { exporting, exportError, pendingFile, exportExcel, openPending } = useExcelExport(() => excel.value, () => `${slug(t('reports.file.stock'))}-${today}.xlsx`);

/* The column header of the table sticks under the tab bar, whatever its height. */
const bar = ref<HTMLElement | null>(null);
const barHeight = ref(0);
let observer: ResizeObserver | undefined;
onMounted(() => {
    if (!bar.value || typeof ResizeObserver === 'undefined') return;
    observer = new ResizeObserver(() => (barHeight.value = bar.value?.offsetHeight ?? 0));
    observer.observe(bar.value);
});
onBeforeUnmount(() => observer?.disconnect());
const headTop = computed(() => `calc(var(--header-height, 3.5rem) + ${barHeight.value}px)`);

const fileMode = isFileMode();
const stockColumns = [fileMode ? 'Stock' : 'Evoliz stock', 'Reserved', 'Broken ones', 'Gifts outside orders', 'Stock to come', 'Threshold', 'To order'] as const;
const stockFields = ['stock', 'reserved', 'broken', 'gifts', 'at_term', 'threshold', 'to_order'] as const;
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Stock by category')" back="/reports">
            <template #actions>
                <a
                    :href="excel"
                    class="inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-accent hover:bg-accent-soft"
                    :class="{ 'opacity-60': exporting }"
                    :aria-busy="exporting"
                    download
                    @click="exportExcel"
                >
                    {{ exporting ? t('Preparing…') : t('Excel') }}
                </a>
            </template>
        </PageHeader>

        <p v-if="exportError" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ exportError }}</p>
        <button
            v-if="pendingFile"
            type="button"
            class="mb-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-accent px-4 font-medium text-white"
            @click="openPending"
        >
            {{ t('Open the Excel file') }}
        </button>
        <p v-if="!fileMode" class="mb-2 text-sm text-muted">{{ t('Evoliz stock updated :age.', { age: formatAge(report.synced_at) }) }}</p>
        <details class="mb-3 rounded-xl border border-line bg-surface">
            <summary class="flex min-h-12 cursor-pointer items-center justify-between gap-2 px-3 text-sm">
                <span>
                    <span class="font-medium">{{ t('Period: :from – :to', { from: formatDate(report.period.from), to: formatDate(report.period.to) }) }}</span>
                    <span class="block text-xs text-muted">{{ t('Broken entries and gifts only; the stock is today’s.') }}</span>
                </span>
                <span aria-hidden="true" class="text-muted">▾</span>
            </summary>
            <div class="border-t border-line p-3">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="item in presets"
                        :key="item.kind"
                        type="button"
                        class="min-h-10 rounded-full border border-line px-3 text-sm font-medium hover:border-accent"
                        @click="preset(item.kind)"
                    >
                        {{ t(item.label) }}
                    </button>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:max-w-md">
                    <TextField v-model="period.from" :label="t('From')" type="date" @change="load" />
                    <TextField v-model="period.to" :label="t('To')" type="date" @change="load" />
                </div>
            </div>
        </details>

        <div ref="bar" class="sticky top-[var(--header-height,3.5rem)] z-[5] -mx-4 border-b border-line bg-page/95 px-4 pt-2 pb-3 backdrop-blur">
            <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2" :aria-label="t('Tabs')">
                <button
                    v-for="candidate in tabs"
                    :key="candidate.key"
                    type="button"
                    class="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm font-medium whitespace-nowrap"
                    :class="candidate.key === active ? 'border-accent bg-accent text-white' : 'border-line bg-surface'"
                    :aria-pressed="candidate.key === active"
                    @click="active = candidate.key"
                >
                    {{ candidate.label }}
                    <span class="tabular-nums opacity-75">{{ listed(candidate).length }}</span>
                    <span v-if="toOrderCount(candidate)" class="rounded-full bg-amber-100 px-1.5 text-xs text-amber-900" :title="t('To order')">{{ toOrderCount(candidate) }}</span>
                </button>
            </nav>
            <SearchField v-model="search" :label="t('Search in :tab', { tab: tab.label })" :delay="150" />
        </div>

        <p v-if="loading" class="mt-3 text-sm text-muted" role="status">{{ t('Loading…') }}</p>

        <!-- Stock tabs -->
        <template v-if="tab.kind === 'stock'">
            <div class="mt-2 mb-1 flex flex-wrap items-center justify-between gap-x-4">
                <p class="text-sm text-muted">
                    {{ tc(':count articles', stockRows.length) }}<template v-if="hidden"> · {{ tc(':count inactive hidden', hidden) }}</template>
                </p>
                <CheckboxField v-if="tab.key !== 'a_classer'" v-model="hideInactive" :label="t('Hide inactive articles')" />
            </div>
            <p v-if="!fileMode" class="mb-2 text-xs text-muted">{{ t('Broken and Gifts: only the entries not yet subtracted from the Evoliz stock (test mode, pending or failed). The others are already in the Evoliz stock.') }}</p>
            <p v-if="tab.key === 'a_classer'" class="mb-2 text-sm text-muted">
                {{ t('Articles with no category, or one to check. They join their category’s tab once classified.') }}
                <Link v-if="isAdmin" href="/admin/article-attributes?review=1" class="font-medium text-accent underline">{{ t('Classify them') }}</Link>
            </p>

            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface lg:hidden">
                <li v-for="row in stockRows" :key="row.article_id" class="px-4 py-3" :class="level(row) ? levelRow[level(row)!] : ''">
                    <p class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ row.reference }}</span>
                        <StatusChip v-if="row.type_label">{{ row.type_label }}</StatusChip>
                        <span v-if="row.category && tab.variant !== true" class="text-xs text-muted">{{ row.category }}</span>
                        <StatusChip v-if="!row.active">{{ t('Inactive') }}</StatusChip>
                    </p>
                    <p class="text-sm break-words text-muted">{{ row.designation }}</p>
                    <dl class="mt-2 grid grid-cols-4 gap-x-2 gap-y-1 text-sm">
                        <div><dt class="text-xs text-muted">{{ t('Stock') }}</dt><dd class="tabular-nums">{{ formatNumber(row.stock) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Reserved') }}</dt><dd class="tabular-nums">{{ formatNumber(row.reserved) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Broken ones') }}</dt><dd class="tabular-nums">{{ formatNumber(row.broken) }}</dd></div>
                        <div><dt class="text-xs text-muted">{{ t('Gifts') }}</dt><dd class="tabular-nums">{{ formatNumber(row.gifts) }}</dd></div>
                        <div>
                            <dt class="text-xs text-muted">{{ t('To come') }}</dt>
                            <dd class="font-semibold tabular-nums" :class="level(row) ? levelText[level(row)!] : ''">{{ formatNumber(row.at_term) }}</dd>
                        </div>
                        <div><dt class="text-xs text-muted">{{ t('Threshold') }}</dt><dd class="tabular-nums">{{ formatNumber(row.threshold) }}</dd></div>
                        <div class="col-span-2">
                            <dt class="text-xs text-muted">{{ t('To order') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ Number(row.to_order ?? 0) > 0 ? formatNumber(row.to_order) : '—' }}</dd>
                        </div>
                    </dl>
                    <p v-if="row.supplier" class="mt-1 text-xs text-muted">{{ row.supplier }}</p>
                </li>
                <li v-if="stockRows.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No article found.') }}</li>
            </ul>

            <table class="hidden w-full table-fixed border-separate border-spacing-0 rounded-xl border border-line bg-surface text-sm lg:table">
                <colgroup>
                    <col class="w-40">
                    <col>
                    <col v-for="field in stockFields" :key="field" class="w-20">
                    <col class="w-28">
                </colgroup>
                <thead>
                    <tr>
                        <th class="sticky z-[4] rounded-tl-xl border-b border-line bg-surface px-2 py-2 text-left" :style="{ top: headTop }">{{ t('Reference') }}</th>
                        <th class="sticky z-[4] border-b border-line bg-surface px-2 py-2 text-left" :style="{ top: headTop }">{{ t('Designation') }}</th>
                        <th v-for="column in stockColumns" :key="column" class="sticky z-[4] border-b border-line bg-surface px-2 py-2 text-right text-xs" :style="{ top: headTop }">
                            {{ t(column) }}
                        </th>
                        <th class="sticky z-[4] rounded-tr-xl border-b border-line bg-surface px-2 py-2 text-left" :style="{ top: headTop }">{{ t('Supplier') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in stockRows" :key="row.article_id" :class="level(row) ? levelRow[level(row)!] : ''">
                        <td class="border-b border-line px-2 py-2 break-words">
                            <span class="block font-medium">{{ row.reference }}</span>
                            <span class="block text-xs text-muted">{{ [row.type_label, tab.variant === true ? null : row.category, row.active ? null : t('Inactive')].filter(Boolean).join(' · ') }}</span>
                        </td>
                        <td class="border-b border-line px-2 py-2 break-words text-muted">{{ row.designation }}</td>
                        <td
                            v-for="field in stockFields"
                            :key="field"
                            class="border-b border-line px-2 py-2 text-right tabular-nums"
                            :class="field === 'at_term' ? ['font-semibold', level(row) ? levelText[level(row)!] : ''] : field === 'to_order' ? 'font-semibold' : ''"
                        >
                            {{ field === 'to_order' && Number(row.to_order ?? 0) === 0 ? '—' : formatNumber(row[field]) }}
                        </td>
                        <td class="border-b border-line px-2 py-2 text-xs break-words text-muted">{{ row.supplier }}</td>
                    </tr>
                    <tr v-if="stockRows.length === 0">
                        <td colspan="10" class="px-2 py-6 text-center text-muted">{{ t('No article found.') }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="!search">
                    <tr class="font-semibold">
                        <td class="px-2 py-2" colspan="2">{{ t('Total') }}</td>
                        <td v-for="field in stockFields" :key="field" class="px-2 py-2 text-right tabular-nums">
                            {{ field === 'threshold' ? '' : formatNumber(tab.totals[field] ?? null) }}
                        </td>
                        <td />
                    </tr>
                </tfoot>
            </table>
            <p v-if="!search" class="mt-2 text-sm font-semibold lg:hidden">
                {{ t('Total: stock :stock · to come :at_term · to order :to_order', {
                    stock: formatNumber(tab.totals.stock ?? null),
                    at_term: formatNumber(tab.totals.at_term ?? null),
                    to_order: formatNumber(tab.totals.to_order ?? null),
                }) }}
            </p>
        </template>

        <!-- Broken entries and gifts of the period -->
        <template v-else>
            <p class="mt-3 mb-2 text-sm text-muted">
                {{ t('From :from to :to', { from: formatDate(report.period.from), to: formatDate(report.period.to) }) }} ·
                {{ tc(':count entries', eventRows.length) }} · {{ t('Total: :quantity', { quantity: formatNumber(tab.totals.quantity) }) }}
            </p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
                <li v-for="(row, index) in eventRows" :key="index" class="flex items-start gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ row.reference ?? '—' }}</span>
                            <span class="tabular-nums">× {{ formatNumber(row.quantity) }}</span>
                            <template v-if="tab.kind === 'gifts'">
                                <Link v-if="row.order_uuid" :href="`/orders/${row.order_uuid}`" class="text-sm text-accent underline">{{ t('Order :number', { number: row.order_number ?? '' }) }}</Link>
                                <StatusChip v-else tone="info">{{ t('Outside an order') }}</StatusChip>
                            </template>
                            <StatusChip v-if="row.evoliz_status && row.evoliz_status !== 'sent'" tone="warning">{{ t('Not yet in the Evoliz stock') }}</StatusChip>
                        </p>
                        <p class="mt-1 text-sm break-words text-muted">{{ row.designation }}</p>
                        <p class="mt-1 text-sm text-muted">
                            {{ formatDate(row.date) }}<template v-if="row.client"> · {{ row.client }}</template><template v-if="row.user"> · {{ row.user }}</template>
                        </p>
                        <p v-if="row.note" class="mt-1 text-sm break-words">{{ row.note }}</p>
                    </div>
                </li>
                <li v-if="eventRows.length === 0" class="px-4 py-6 text-center text-muted">{{ t('Nothing recorded for this period.') }}</li>
            </ul>
        </template>
    </AppLayout>
</template>
