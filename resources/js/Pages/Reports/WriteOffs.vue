<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationNav from '@/components/PaginationNav.vue';
import StatusChip from '@/components/StatusChip.vue';
import TextField from '@/components/TextField.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { slug } from '@/lib/download';
import { useExcelExport } from '@/lib/excelExport';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import type { Paginated } from '@/types';

type WriteOffType = 'broken' | 'order_gift' | 'gift';
type EvolizStatus = 'pending' | 'sent' | 'failed' | 'disabled' | 'skipped';

interface Entry {
    key: string;
    type: WriteOffType;
    date: string;
    recorded_at: string;
    quantity: string;
    reference: string | null;
    designation: string;
    client: string | null;
    order_number: string | null;
    order_uuid: string | null;
    note: string | null;
    user: string;
    user_id: number;
    evoliz_status: EvolizStatus | null;
}

interface Total {
    entries: number;
    quantity: string;
}

const props = defineProps<{
    period: { from: string; to: string };
    presets: { key: 'this_year' | 'last_year' | 'this_quarter' | 'last_quarter'; from: string; to: string }[];
    types: WriteOffType[];
    totals: Total & { by_type: Partial<Record<WriteOffType, Total>> };
    entries: Paginated<Entry>;
}>();

const allTypes: { value: WriteOffType; label: string; chip: string; tone: 'danger' | 'info' | 'success' }[] = [
    { value: 'broken', label: t('Broken entries'), chip: t('Broken'), tone: 'danger' },
    { value: 'order_gift', label: t('Gifts in an order'), chip: t('Gift in an order'), tone: 'success' },
    { value: 'gift', label: t('Gifts outside an order'), chip: t('Gift outside an order'), tone: 'info' },
];
const typeOf = Object.fromEntries(allTypes.map((type) => [type.value, type])) as Record<WriteOffType, (typeof allTypes)[number]>;

const presetLabels = { this_year: 'This year', last_year: 'Last year', this_quarter: 'This quarter', last_quarter: 'Previous quarter' } as const;
const activePreset = computed(() => props.presets.find((preset) => preset.from === props.period.from && preset.to === props.period.to)?.key ?? null);

/* Filters: every change reloads the first page. All three types is the default, so it stays out of the URL. */
const filters = reactive({ from: props.period.from, to: props.period.to, types: [...props.types] });
const loading = ref(false);

function query(): Record<string, string | string[]> {
    const data: Record<string, string | string[]> = { from: filters.from, to: filters.to };
    if (filters.types.length !== allTypes.length) data.types = allTypes.map((type) => type.value).filter((type) => filters.types.includes(type));
    return data;
}

function load(): void {
    if (!filters.from || !filters.to) return;
    router.get('/reports/write-offs', query(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
        onSuccess: () => {
            filters.from = props.period.from;
            filters.to = props.period.to;
        },
    });
}

function choose(preset: (typeof props.presets)[number]): void {
    filters.from = preset.from;
    filters.to = preset.to;
    load();
}

function toggle(type: WriteOffType, on: boolean): void {
    filters.types = on ? [...filters.types, type] : filters.types.filter((candidate) => candidate !== type);
    load();
}

/* The download takes the period and the types on screen. */
const excel = computed(() => {
    const params = new URLSearchParams({ from: props.period.from, to: props.period.to });
    if (props.types.length !== allTypes.length) props.types.forEach((type) => params.append('types[]', type));
    return `/reports/write-offs.xlsx?${params.toString()}`;
});
const { exporting, exportError, pendingFile, exportExcel, openPending } = useExcelExport(
    () => excel.value,
    () => `${slug(t('reports.file.write_offs'))}-${props.period.from}-${props.period.to}.xlsx`,
);

const user = computed(() => usePage().props.auth.user);
function canOpen(entry: Entry): boolean {
    return entry.order_uuid !== null && (user.value?.role === 'admin' || user.value?.id === entry.user_id);
}

/* Same wording as the stock adjustments page. Gifts in an order leave the stock with their delivery note. */
const evolizChip: Record<EvolizStatus, { tone: 'neutral' | 'success' | 'warning' | 'danger'; label: string }> = {
    pending: { tone: 'neutral', label: t('Evoliz: in progress') },
    sent: { tone: 'success', label: t('Evoliz: stock updated') },
    failed: { tone: 'danger', label: t('Evoliz: failed') },
    disabled: { tone: 'warning', label: t('Evoliz: not sent (test mode)') },
    skipped: { tone: 'neutral', label: t('Evoliz: no stock management') },
};
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Broken entries and gifts')" back="/reports">
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

        <section class="mb-4 grid gap-4 rounded-xl border border-line bg-surface p-3 lg:grid-cols-[1fr_auto] lg:gap-8">
            <div>
                <p class="mb-2 text-sm font-medium">{{ t('Period: :from – :to', { from: formatDate(period.from), to: formatDate(period.to) }) }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        type="button"
                        class="min-h-10 rounded-full border px-3 text-sm font-medium"
                        :class="preset.key === activePreset ? 'border-accent bg-accent text-white' : 'border-line hover:border-accent'"
                        :aria-pressed="preset.key === activePreset"
                        @click="choose(preset)"
                    >
                        {{ t(presetLabels[preset.key]) }}
                    </button>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:max-w-md">
                    <TextField v-model="filters.from" :label="t('From')" type="date" @change="load" />
                    <TextField v-model="filters.to" :label="t('To')" type="date" @change="load" />
                </div>
            </div>
            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ t('Type') }}</legend>
                <CheckboxField
                    v-for="type in allTypes"
                    :key="type.value"
                    :model-value="filters.types.includes(type.value)"
                    :label="type.label"
                    :disabled="filters.types.length === 1 && filters.types[0] === type.value"
                    @update:model-value="toggle(type.value, $event)"
                />
            </fieldset>
        </section>

        <div class="mb-2 text-sm text-muted" role="status">
            <p v-if="loading">{{ t('Loading…') }}</p>
            <p v-else>
                {{ tc(':count entries', totals.entries) }} · {{ t('Total: :quantity', { quantity: formatNumber(totals.quantity) }) }}
            </p>
            <p v-if="types.length > 1" class="mt-0.5 flex flex-wrap gap-x-3">
                <span v-for="type in types" :key="type" class="whitespace-nowrap">
                    {{ t(':label: :quantity', { label: typeOf[type].label, quantity: formatNumber(totals.by_type[type]?.quantity ?? '0') }) }}
                </span>
            </p>
        </div>

        <!-- Phones and tablets -->
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface lg:hidden">
            <li v-for="entry in entries.data" :key="entry.key" class="px-4 py-3">
                <p class="flex flex-wrap items-center gap-2">
                    <StatusChip :tone="typeOf[entry.type].tone">{{ typeOf[entry.type].chip }}</StatusChip>
                    <span class="font-medium">{{ entry.reference ?? '—' }}</span>
                    <span class="tabular-nums">× {{ formatNumber(entry.quantity) }}</span>
                </p>
                <p class="mt-1 text-sm break-words text-muted">{{ entry.designation }}</p>
                <p class="mt-1 text-sm">
                    {{ formatDate(entry.date) }}<template v-if="entry.client"> · {{ entry.client }}</template>
                    <template v-if="entry.order_number">
                        ·
                        <Link v-if="canOpen(entry)" :href="`/orders/${entry.order_uuid}`" class="text-accent underline">{{ t('Order :number', { number: entry.order_number }) }}</Link>
                        <span v-else>{{ t('Order :number', { number: entry.order_number }) }}</span>
                    </template>
                </p>
                <p class="mt-1 text-xs text-muted">{{ t('Recorded on :date by :user', { date: formatDateTime(entry.recorded_at), user: entry.user }) }}</p>
                <p v-if="entry.note" class="mt-1 text-sm break-words">{{ entry.note }}</p>
                <p v-if="entry.evoliz_status" class="mt-2">
                    <StatusChip :tone="evolizChip[entry.evoliz_status].tone">{{ evolizChip[entry.evoliz_status].label }}</StatusChip>
                </p>
            </li>
            <li v-if="entries.data.length === 0" class="px-4 py-6 text-center text-muted">{{ t('Nothing recorded for these filters.') }}</li>
        </ul>

        <!-- Desktop -->
        <table class="hidden w-full table-fixed border-separate border-spacing-0 rounded-xl border border-line bg-surface text-sm lg:table">
            <colgroup>
                <col class="w-28">
                <col class="w-40">
                <col>
                <col class="w-14">
                <col class="w-44 xl:w-52">
                <col class="w-36 xl:w-48">
                <col class="w-36 xl:w-44">
            </colgroup>
            <thead>
                <tr class="text-left">
                    <th class="border-b border-line px-3 py-2">{{ t('Date') }}</th>
                    <th class="border-b border-line px-3 py-2">{{ t('Type') }}</th>
                    <th class="border-b border-line px-3 py-2">{{ t('Article') }}</th>
                    <th class="border-b border-line px-3 py-2 text-right">{{ t('Qty') }}</th>
                    <th class="border-b border-line px-3 py-2">{{ t('Client / order') }}</th>
                    <th class="border-b border-line px-3 py-2">{{ t('Note') }}</th>
                    <th class="border-b border-line px-3 py-2">{{ t('Recorded by') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries.data" :key="entry.key" class="align-top">
                    <td class="border-b border-line px-3 py-2">{{ formatDate(entry.date) }}</td>
                    <td class="border-b border-line px-3 py-2">
                        <StatusChip :tone="typeOf[entry.type].tone" wrap>{{ typeOf[entry.type].chip }}</StatusChip>
                        <span v-if="entry.evoliz_status" class="mt-1 block">
                            <StatusChip :tone="evolizChip[entry.evoliz_status].tone" wrap>{{ evolizChip[entry.evoliz_status].label }}</StatusChip>
                        </span>
                    </td>
                    <td class="border-b border-line px-3 py-2 break-words">
                        <span class="block font-medium">{{ entry.reference ?? '—' }}</span>
                        <span class="block text-xs text-muted">{{ entry.designation }}</span>
                    </td>
                    <td class="border-b border-line px-3 py-2 text-right font-semibold tabular-nums">{{ formatNumber(entry.quantity) }}</td>
                    <td class="border-b border-line px-3 py-2 break-words">
                        <span v-if="entry.client" class="block">{{ entry.client }}</span>
                        <template v-if="entry.order_number">
                            <Link v-if="canOpen(entry)" :href="`/orders/${entry.order_uuid}`" class="text-accent underline">{{ t('Order :number', { number: entry.order_number }) }}</Link>
                            <span v-else class="text-muted">{{ t('Order :number', { number: entry.order_number }) }}</span>
                        </template>
                        <span v-if="!entry.client && !entry.order_number" class="text-muted">—</span>
                    </td>
                    <td class="border-b border-line px-3 py-2 break-words">{{ entry.note ?? '' }}</td>
                    <td class="border-b border-line px-3 py-2 break-words">
                        <span class="block">{{ entry.user }}</span>
                        <span class="block text-xs text-muted">{{ formatDateTime(entry.recorded_at) }}</span>
                    </td>
                </tr>
                <tr v-if="entries.data.length === 0">
                    <td colspan="7" class="px-3 py-6 text-center text-muted">{{ t('Nothing recorded for these filters.') }}</td>
                </tr>
            </tbody>
        </table>

        <PaginationNav :page="entries" />
    </AppLayout>
</template>
