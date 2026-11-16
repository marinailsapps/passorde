<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useExcelExport } from '@/lib/excelExport';
import { formatAge, formatDate, formatDay, formatMoney, formatMonth, formatNumber } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import { catalogProp } from '@/lib/catalog';
import { GROUPINGS, type Grouping, type SalesFilters, type SalesGroupRow, type SalesResult } from '@/lib/salesReport';
import { currentGrouping } from '@/offline/legacy';
import { isOffline } from '@/offline/runtime';

/*
 * "Ventes" (App\Domain\Reports\SalesReport): the Evoliz delivery notes of a
 * period, never the app orders still waiting. Offline the same page is
 * computed on the phone from its copy of the BLs (offline/pages.ts). In file
 * mode: the exported orders, online only, with no invoiced state.
 */
const props = defineProps<{
    filters: SalesFilters;
    presets: { key: 'this_week' | 'last_week' | 'this_month' | 'this_quarter' | 'this_year'; from: string; to: string }[];
    sellers: { id: number; name: string }[];
    syncedAt: string | null;
    report: SalesResult;
    /** Rendered from the phone's copy; false when the BL history is not complete there yet. */
    historyComplete?: boolean;
    /** Online only: the basis of the figures (App\\Domain\\Reports\\SalesReport::fromEvoliz). */
    source?: 'evoliz' | 'file';
}>();

const local = computed(() => usePage().props.offline === true);
// The server says which basis its figures have (Evoliz BLs, also while a lapsed licence freezes them); offline, the mode.
const fileMode = props.source !== undefined ? props.source === 'file' : isFileMode();

const presetLabels = { this_week: 'Current week', last_week: 'Last week', this_month: 'Current month', this_quarter: 'Current quarter', this_year: 'Current year' } as const;
const activePreset = computed(() => props.presets.find((preset) => preset.from === props.filters.from && preset.to === props.filters.to)?.key ?? null);

const form = reactive({ from: props.filters.from, to: props.filters.to, delivered: props.filters.delivered, invoiced: props.filters.invoiced, seller: props.filters.seller_id === null ? '' : String(props.filters.seller_id) });
const loading = ref(false);

/* The grouping is screen state; it rides along in the URL so a reload keeps it. */
const groupParam = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('group');
/* A link saved under an older grouping name (the profile's aliases.groupings) opens the current one. */
const initialGroup = currentGrouping(groupParam);
/* Without categories in the profile there is no grouping by category: the default is then by article. */
const groupings = GROUPINGS.filter((value) => value !== 'category' || catalogProp().has_categories !== false);
const grouping = ref<Grouping>(groupings.includes(initialGroup as Grouping) ? (initialGroup as Grouping) : groupings[0]);

function query(): Record<string, string> {
    const data: Record<string, string> = { from: form.from, to: form.to, group: grouping.value };
    // Both statuses is the default; a lone one goes in the URL.
    if (!form.delivered || !form.invoiced) {
        data.delivered = form.delivered ? '1' : '0';
        data.invoiced = form.invoiced ? '1' : '0';
    }
    if (form.seller !== '') data.seller = form.seller;
    return data;
}

function load(): void {
    if (!form.from || !form.to) return;
    router.get('/reports/sales', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onSuccess: () => Object.assign(form, { from: props.filters.from, to: props.filters.to }),
    });
}

function choose(preset: (typeof props.presets)[number]): void {
    form.from = preset.from;
    form.to = preset.to;
    load();
}

function setStatus(which: 'delivered' | 'invoiced', on: boolean): void {
    form[which] = on;
    load();
}

function setGrouping(value: Grouping): void {
    grouping.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set('group', value);
    window.history.replaceState(window.history.state, '', url);
}

const groupLabels: Record<Grouping, string> = { category: 'By category', article: 'By article', client: 'By client', month: 'By month', department: 'By département', variant: 'By variant' };

/* Sorting: a click on a header sorts by it, a second click reverses. */
type SortKey = 'label' | 'count' | 'quantity' | 'free_quantity' | 'ht' | 'ttc';
const sort = reactive<{ key: SortKey | null; desc: boolean }>({ key: null, desc: true });
function sortBy(key: SortKey): void {
    sort.desc = sort.key === key ? !sort.desc : key !== 'label';
    sort.key = key;
}

const labelOf = (row: SalesGroupRow): string => {
    if (row.label === null) return t(`sales.${row.key}`);
    return grouping.value === 'month' ? formatMonth(row.key) : row.label;
};

const rows = computed(() => {
    const list = [...props.report.groups[grouping.value]];
    if (sort.key === null) return list;
    const key = sort.key;
    const sign = sort.desc ? -1 : 1;
    return list.sort((a, b) => sign * (key === 'label' ? labelOf(a).localeCompare(labelOf(b), 'fr') : key === 'count' ? a.count - b.count : Number(a[key]) - Number(b[key])));
});

/* Drill-down: the BLs of a group. */
const opened = ref<SalesGroupRow | null>(null);
const byId = computed(() => new Map(props.report.deliveries.map((delivery) => [delivery.id, delivery])));
const openedDeliveries = computed(() => (opened.value ? opened.value.deliveries.map((id) => byId.value.get(id)).filter((d) => d !== undefined) : []));

/* Excel: online only. */
const excel = computed(() => {
    const params = new URLSearchParams(query());
    params.delete('group');
    return `/reports/sales.xlsx?${params.toString()}`;
});
const { exporting, exportError, pendingFile, exportExcel, openPending } = useExcelExport(
    () => excel.value,
    () => `ventes-${props.filters.from}-${props.filters.to}.xlsx`,
);
const offlineExcel = ref(false);
function onExcel(event: MouseEvent): void {
    if (local.value || isOffline()) {
        event.preventDefault();
        offlineExcel.value = true;
        return;
    }
    void exportExcel(event);
}

const columns: { key: SortKey; label: string; class: string }[] = [
    { key: 'label', label: 'Label', class: 'text-left' },
    { key: 'count', label: fileMode ? 'Orders' : 'BLs', class: 'text-right' },
    { key: 'quantity', label: 'Quantities', class: 'text-right' },
    { key: 'free_quantity', label: 'of which free', class: 'hidden text-right sm:table-cell' },
    { key: 'ht', label: 'HT', class: 'text-right' },
    { key: 'ttc', label: 'TTC', class: 'hidden text-right sm:table-cell' },
];
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Sales report')" back="/reports">
            <template #actions>
                <a
                    :href="excel"
                    class="inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-accent hover:bg-accent-soft"
                    :class="{ 'opacity-60': exporting || local }"
                    :aria-busy="exporting"
                    data-flow="sales-excel"
                    download
                    @click="onExcel"
                >
                    {{ exporting ? t('Preparing…') : t('Excel') }}
                </a>
            </template>
        </PageHeader>

        <p v-if="offlineExcel" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
            {{ t('The Excel download needs a connection. The report itself works offline.') }}
        </p>
        <p v-if="exportError" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ exportError }}</p>
        <button
            v-if="pendingFile"
            type="button"
            class="mb-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-accent px-4 font-medium text-white"
            @click="openPending"
        >
            {{ t('Open the Excel file') }}
        </button>

        <p v-if="local" class="mb-3 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700" data-flow="sales-offline">
            {{ historyComplete === false ? t('Offline: computed on this phone, whose copy of the delivery notes is still loading; figures may be incomplete.') : t('Offline: computed on this phone from its copy of the delivery notes.') }}
        </p>

        <section class="mb-4 grid gap-4 rounded-xl border border-line bg-surface p-3 lg:grid-cols-[1fr_auto] lg:gap-8">
            <div>
                <p class="mb-2 text-sm font-medium">{{ t('Period: :from – :to', { from: formatDate(filters.from), to: formatDate(filters.to) }) }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        type="button"
                        class="min-h-10 rounded-full border px-3 text-sm font-medium"
                        :class="preset.key === activePreset ? 'border-accent bg-accent text-white' : 'border-line hover:border-accent'"
                        :aria-pressed="preset.key === activePreset"
                        :data-flow="`sales-preset-${preset.key}`"
                        @click="choose(preset)"
                    >
                        {{ t(presetLabels[preset.key]) }}
                    </button>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:max-w-md">
                    <TextField v-model="form.from" :label="t('From')" type="date" @change="load" />
                    <TextField v-model="form.to" :label="t('To')" type="date" @change="load" />
                </div>
            </div>
            <div class="flex flex-wrap content-start gap-x-6 lg:grid lg:gap-1">
                <template v-if="!fileMode">
                    <CheckboxField
                        :model-value="form.delivered"
                        :label="t('Delivery notes')"
                        :disabled="form.delivered && !form.invoiced"
                        @update:model-value="setStatus('delivered', $event)"
                    />
                    <CheckboxField :model-value="form.invoiced" :label="t('Invoiced')" :disabled="form.invoiced && !form.delivered" @update:model-value="setStatus('invoiced', $event)" />
                </template>
                <label v-if="sellers.length" class="mt-1 block w-full">
                    <span class="mb-1 block text-sm font-medium">{{ t('Seller') }}</span>
                    <select v-model="form.seller" class="min-h-12 w-full rounded-lg border border-field bg-surface px-3 sm:w-64" data-flow="sales-seller" @change="load">
                        <option value="">{{ t('All sellers') }}</option>
                        <option v-for="seller in sellers" :key="seller.id" :value="String(seller.id)">{{ seller.name }}</option>
                    </select>
                </label>
            </div>
        </section>

        <!-- Cards -->
        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5" role="status" data-flow="sales-cards">
            <div class="rounded-xl border border-line bg-surface p-3">
                <p class="text-xs text-muted">{{ fileMode ? t('Orders') : t('Orders (BL)') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ loading ? '…' : formatNumber(report.cards.count) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-3">
                <p class="text-xs text-muted">{{ t('Quantities') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ formatNumber(report.cards.quantity) }}</p>
                <p class="text-xs text-muted">{{ t('of which :count free', { count: formatNumber(report.cards.free_quantity) }) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-3">
                <p class="text-xs text-muted">{{ t('HT') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ formatMoney(report.cards.ht) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-3">
                <p class="text-xs text-muted">{{ t('VAT') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ formatMoney(report.cards.vat) }}</p>
            </div>
            <div class="col-span-2 rounded-xl border border-line bg-surface p-3 sm:col-span-1">
                <p class="text-xs text-muted">{{ t('TTC') }}</p>
                <p class="text-xl font-semibold tabular-nums">{{ formatMoney(report.cards.ttc) }}</p>
            </div>
        </div>

        <!-- Grouping -->
        <div class="-mx-4 mb-2 overflow-x-auto px-4" role="tablist" :aria-label="t('Group by')">
            <div class="flex w-max gap-1 rounded-xl bg-page p-1">
                <button
                    v-for="value in groupings"
                    :key="value"
                    type="button"
                    role="tab"
                    class="min-h-10 rounded-lg px-3 text-sm font-medium whitespace-nowrap"
                    :class="grouping === value ? 'bg-surface shadow-sm' : 'text-muted hover:text-ink'"
                    :aria-selected="grouping === value"
                    :data-flow="`sales-group-${value}`"
                    @click="setGrouping(value)"
                >
                    {{ t(groupLabels[value]) }}
                </button>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-line bg-surface">
            <table class="w-full table-fixed text-sm" data-flow="sales-table">
                <colgroup>
                    <col>
                    <col class="w-12">
                    <col class="w-16">
                    <col class="hidden w-20 sm:table-column">
                    <col class="w-24 sm:w-28">
                    <col class="hidden w-28 sm:table-column">
                </colgroup>
                <thead class="border-b border-line bg-page text-xs text-muted">
                    <tr>
                        <th v-for="column in columns" :key="column.key" scope="col" class="px-2 py-2 font-medium first:pl-3 last:pr-3" :class="column.class">
                            <button type="button" class="inline-flex min-h-8 items-center gap-1" @click="sortBy(column.key)">
                                {{ t(column.label) }}<span v-if="sort.key === column.key" aria-hidden="true">{{ sort.desc ? '↓' : '↑' }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in rows" :key="row.key" class="cursor-pointer hover:bg-page" data-flow="sales-row" @click="opened = row">
                        <td class="truncate py-2 pr-2 pl-3 font-medium" :title="labelOf(row)">{{ labelOf(row) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">{{ formatNumber(row.count) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums">
                            {{ formatNumber(row.quantity) }}
                            <span v-if="Number(row.free_quantity) > 0" class="block text-xs text-muted sm:hidden">{{ t(':count free', { count: formatNumber(row.free_quantity) }) }}</span>
                        </td>
                        <td class="hidden px-2 py-2 text-right tabular-nums sm:table-cell">{{ formatNumber(row.free_quantity) }}</td>
                        <td class="px-2 py-2 text-right tabular-nums sm:pr-2 max-sm:pr-3">{{ formatMoney(row.ht) }}</td>
                        <td class="hidden py-2 pr-3 pl-2 text-right tabular-nums sm:table-cell">{{ formatMoney(row.ttc) }}</td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="6" class="px-3 py-6 text-center text-muted">{{ fileMode ? t('No exported order in this period.') : t('No delivery note in this period.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-muted">
            {{ fileMode ? t('Source: exported orders; the others are not counted. Quantities leave out the variants that are not counted.') : t('Source: Evoliz delivery notes; orders still waiting are not counted. Quantities leave out the variants that are not counted.') }}
            <template v-if="syncedAt"> {{ t('Delivery notes synchronised :age.', { age: formatAge(syncedAt) }) }}</template>
        </p>

        <BottomSheet :open="opened !== null" :title="opened ? labelOf(opened) : ''" @close="opened = null">
            <p v-if="opened" class="mb-3 text-sm text-muted">
                {{ tc(fileMode ? ':count orders' : ':count delivery notes', opened.count) }} · {{ formatMoney(opened.ht) }} {{ t('HT') }} · {{ formatMoney(opened.ttc) }} {{ t('TTC') }}
            </p>
            <ul class="divide-y divide-line" data-flow="sales-drilldown">
                <li v-for="delivery in openedDeliveries" :key="delivery.id" class="py-2 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ delivery.number }}</span>
                        <span class="text-muted tabular-nums">{{ formatDay(delivery.date) }}</span>
                        <span class="flex-1" />
                        <StatusChip :tone="delivery.invoiced ? 'success' : 'info'">{{ fileMode ? t('order.label.exported') : t(delivery.invoiced ? 'history.invoiced' : 'history.delivered') }}</StatusChip>
                    </div>
                    <div class="flex gap-2">
                        <span class="min-w-0 flex-1 truncate text-muted">{{ delivery.client }}</span>
                        <span class="tabular-nums">{{ formatMoney(delivery.ht) }} {{ t('HT') }}</span>
                    </div>
                </li>
            </ul>
        </BottomSheet>
    </AppLayout>
</template>
