<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

import ConfirmDialog from '@/components/ConfirmDialog.vue';
import StatusChip from '@/components/StatusChip.vue';
import PageHeader from '@/components/PageHeader.vue';
import SearchField from '@/components/SearchField.vue';
import UiButton from '@/components/UiButton.vue';
import OrderStatusChip from '@/components/orders/OrderStatusChip.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ApiError, apiGet, apiPost } from '@/lib/api';
import { deliverFile, fetchFile, saveFile } from '@/lib/download';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import { testModeApprovalNote } from '@/lib/testMode';
import { toCents } from '@/lib/orderTotals';
import { isStandalone } from '@/offline/pwa';
import type { OrderStatus, OrderSummary } from '@/types';

const props = defineProps<{ authors: { id: number; name: string }[]; exportLimit: number }>();

/** "conflict": orders that would take an article's stock below zero. "to_export", "exported": file mode. */
type StatusFilter = OrderStatus | 'all' | 'conflict' | 'to_export' | 'exported';
type Period = 'all' | 'month' | '30days' | 'year';

const filters = reactive<{ status: StatusFilter; userId: string; period: Period; q: string }>({ status: 'pending', userId: '', period: 'all', q: '' });
const orders = ref<OrderSummary[]>([]);
const counts = ref<Record<StatusFilter, number>>({ pending: 0, approved: 0, rejected: 0, sent: 0, failed: 0, all: 0, conflict: 0, to_export: 0, exported: 0 });
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const message = ref<{ tone: 'success' | 'error'; text: string } | null>(null);
const selected = ref<Set<string>>(new Set());
const bulkOpen = ref(false);
const bulkBusy = ref(false);

const fileMode = isFileMode();

const chips: { value: StatusFilter; label: string }[] = [
    { value: 'pending', label: 'order.status.pending' },
    { value: 'approved', label: 'order.status.approved' },
    { value: 'failed', label: 'order.label.failed' },
    { value: 'sent', label: 'order.status.sent' },
    { value: 'to_export', label: 'order.filter.to_export' },
    { value: 'exported', label: 'order.filter.exported' },
    { value: 'rejected', label: 'order.status.rejected' },
    { value: 'all', label: 'All' },
    { value: 'conflict', label: 'Insufficient stock' },
].filter((chip) => !(fileMode ? ['failed', 'sent'] : ['to_export', 'exported']).includes(chip.value)) as { value: StatusFilter; label: string }[];

/* File mode: these two lists tick orders for a file instead of for approval. */
const exportList = computed(() => filters.status === 'to_export' || filters.status === 'exported');

/** "GALA GD POM, REINETTE POM" for the red badge. */
const shortRefs = (order: OrderSummary): string => (order.stock_conflicts ?? []).map((conflict) => conflict.reference).join(', ');

const iso = (date: Date): string => date.toLocaleDateString('sv-SE', { timeZone: 'Europe/Paris' });

function from(period: Period): string | undefined {
    const now = new Date();
    if (period === 'month') return iso(new Date(now.getFullYear(), now.getMonth(), 1));
    if (period === '30days') return iso(new Date(now.getTime() - 30 * 86400000));
    if (period === 'year') return iso(new Date(now.getFullYear(), 0, 1));
    return undefined;
}

async function load(reset: boolean): Promise<void> {
    loading.value = true;
    const status = filters.status;
    try {
        const response = await apiGet<{ data: OrderSummary[]; meta: { current_page: number; last_page: number }; counts: Record<StatusFilter, number> }>(
            '/api/admin/orders',
            {
                status: filters.status,
                user_id: filters.userId,
                q: filters.q.trim().length >= 2 ? filters.q : undefined,
                from: from(filters.period),
                page: reset ? 1 : page.value + 1,
            },
        );
        // A chip tapped meanwhile: its own answer is on the way, and this one must not tick orders.
        if (fileMode && filters.status !== status) return;
        orders.value = reset ? response.data : [...orders.value, ...response.data];
        // "À exporter": everything listed is ticked, the operator unticks what must wait.
        const ticked = filters.status === 'to_export' ? response.data.map((order) => order.uuid) : [];
        counts.value = response.counts;
        page.value = response.meta.current_page;
        lastPage.value = response.meta.last_page;
        selected.value = new Set(reset ? ticked : [...selected.value, ...ticked]);
    } catch {
        message.value = { tone: 'error', text: t('The orders could not be loaded. Check the connection.') };
    } finally {
        loading.value = false;
    }
}

function setStatus(status: StatusFilter): void {
    filters.status = status;
    load(true);
}

function toggle(order: OrderSummary): void {
    const next = new Set(selected.value);
    if (next.has(order.uuid)) next.delete(order.uuid);
    else next.add(order.uuid);
    selected.value = next;
}

const canSelect = (order: OrderSummary): boolean => exportList.value || order.can_approve;
const selectable = computed(() => orders.value.filter(canSelect));
const allSelected = computed(() => selectable.value.length > 0 && selectable.value.every((order) => selected.value.has(order.uuid)));

function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set(selectable.value.map((order) => order.uuid));
}

const selectedOrders = computed(() => orders.value.filter((order) => selected.value.has(order.uuid)));
const selectedTtc = computed(() => selectedOrders.value.reduce((sum, order) => sum + toCents(order.total_ttc), 0) / 100);
const selectedShort = computed(() => selectedOrders.value.filter((order) => order.stock_conflicts?.length));
const bulkMessage = computed(() => {
    const message = tc(fileMode ? 'Approve :count orders, :ttc TTC in total?' : 'Create :count delivery notes in Evoliz, :ttc TTC in total? Each BL is registered immediately and cannot be cancelled from the app.', selected.value.size, {
        ttc: formatMoney(selectedTtc.value),
    });
    const note = testModeApprovalNote(selected.value.size);
    const sms = usePage().props.smsEnabled ? t(fileMode ? 'Each client with a mobile number will receive an SMS.' : 'Each client with a mobile number will receive an SMS once its delivery note exists.') : '';
    if (!selectedShort.value.length) return [note, message, sms].filter(Boolean).join(' ');
    const detail = selectedShort.value
        .map((order) => `${order.number} : ${(order.stock_conflicts ?? []).map((c) => t(':reference (short by :shortfall)', { reference: c.reference, shortfall: formatNumber(c.shortfall) })).join(', ')}`)
        .join(' ; ');
    return [note, message, t('Insufficient stock: :articles.', { articles: detail }), sms].filter(Boolean).join(' ');
});

async function bulkApprove(): Promise<void> {
    bulkBusy.value = true;
    try {
        const response = await apiPost<{ approved: string[]; skipped: { number: string; reason: string }[] }>('/api/admin/orders/approve', {
            uuids: [...selected.value],
        });
        message.value = {
            tone: response.skipped.length ? 'error' : 'success',
            text: [
                tc(fileMode ? ':count orders approved.' : ':count orders approved; the delivery notes are being created.', response.approved.length),
                ...response.skipped.map((s) => `${s.number} : ${s.reason}`),
            ].join(' '),
        };
        bulkOpen.value = false;
        await load(true);
    } catch (e) {
        message.value = { tone: 'error', text: e instanceof ApiError ? e.messages.join(' ') : t('The action failed. Check the connection and try again.') };
        bulkOpen.value = false;
    } finally {
        bulkBusy.value = false;
    }
}

/*
 * Export, two steps: the file first, then "mark as exported" for the orders that
 * were in it. A failed download marks nothing. An order approved since the list
 * loaded was not in the file, so the mark takes the file's uuids.
 */
/** Orders in one file: the server's limit (App\Domain\Orders\OrderExport::MAX_ORDERS). */
const MAX_FILE_ORDERS = props.exportLimit;
const downloading = ref(false);
const fileUuids = ref<string[]>([]);
const markOpen = ref(false);
const markBusy = ref(false);
const pendingExport = ref<{ file: File; uuids: string[] } | null>(null);

function offerMark(uuids: string[]): void {
    if (filters.status !== 'to_export') return;
    fileUuids.value = uuids;
    markOpen.value = true;
}

async function download(format: 'csv' | 'xlsx'): Promise<void> {
    if (selected.value.size > MAX_FILE_ORDERS) {
        message.value = { tone: 'error', text: t('A file holds :max orders at most. Untick some, then download again.', { max: MAX_FILE_ORDERS }) };
        return;
    }
    const uuids = [...selected.value];
    const query = new URLSearchParams(uuids.map((uuid) => ['uuids[]', uuid]));
    downloading.value = true;
    message.value = null;
    pendingExport.value = null;
    try {
        const file = await fetchFile(`/admin/orders/export.${format}?${query}`, `commandes.${format}`);
        // In the installed app, the share sheet (lib/download.ts); in a browser tab, a normal download.
        if (isStandalone()) {
            if ((await deliverFile(file)) === 'needs-tap') {
                pendingExport.value = { file, uuids };
                return;
            }
        } else {
            saveFile(file);
        }
        offerMark(uuids);
    } catch {
        message.value = { tone: 'error', text: t('The file could not be downloaded, so nothing was marked. Check the connection and try again.') };
    } finally {
        downloading.value = false;
    }
}

async function openPendingExport(): Promise<void> {
    const pending = pendingExport.value;
    if (!pending) return;
    pendingExport.value = null;
    if ((await deliverFile(pending.file)) === 'needs-tap') pendingExport.value = pending;
    else offerMark(pending.uuids);
}

async function markExported(): Promise<void> {
    markBusy.value = true;
    try {
        const response = await apiPost<{ exported: string[]; skipped: { number: string; reason: string }[] }>('/api/admin/orders/mark-exported', { uuids: fileUuids.value });
        message.value = {
            tone: response.skipped.length ? 'error' : 'success',
            text: [tc(':count orders marked as exported.', response.exported.length), ...response.skipped.map((s) => `${s.number} : ${s.reason}`)].join(' '),
        };
        markOpen.value = false;
        await load(true);
    } catch (e) {
        message.value = { tone: 'error', text: e instanceof ApiError ? e.messages.join(' ') : t('The action failed. Check the connection and try again.') };
        markOpen.value = false;
    } finally {
        markBusy.value = false;
    }
}

// Refresh while pushes are running, so approved orders turn into sent (or failed).
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    load(true);
    timer = setInterval(() => {
        if (!fileMode && !loading.value && counts.value.approved > 0) load(true);
    }, 5000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('All orders')" back="/" />

        <div class="sticky top-[var(--header-height,3.5rem)] z-[5] -mx-4 mb-3 space-y-2 border-b border-line bg-page/95 px-4 pt-1 pb-3 backdrop-blur">
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" :aria-label="t('Status')">
                <button
                    v-for="chip in chips"
                    :key="chip.value"
                    type="button"
                    :data-flow="`status-${chip.value}`"
                    class="min-h-10 shrink-0 rounded-full border px-4 text-sm"
                    :class="filters.status === chip.value ? 'border-accent bg-accent text-white' : 'border-line bg-surface'"
                    :aria-pressed="filters.status === chip.value"
                    @click="setStatus(chip.value)"
                >
                    {{ t(chip.label) }} <span class="tabular-nums opacity-80">{{ counts[chip.value] ?? 0 }}</span>
                </button>
            </div>
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-[1fr_14rem_12rem]">
                <SearchField v-model="filters.q" class="col-span-2 lg:col-span-1" :label="t('Client')" @search="load(true)" />
                <select v-model="filters.userId" class="min-h-12 rounded-lg border border-field bg-surface px-3" :aria-label="t('Author')" @change="load(true)">
                    <option value="">{{ t('All sales people') }}</option>
                    <option v-for="author in authors" :key="author.id" :value="String(author.id)">{{ author.name }}</option>
                </select>
                <select v-model="filters.period" class="min-h-12 rounded-lg border border-field bg-surface px-3" :aria-label="t('Period')" @change="load(true)">
                    <option value="all">{{ t('All dates') }}</option>
                    <option value="month">{{ t('This month') }}</option>
                    <option value="30days">{{ t('Last 30 days') }}</option>
                    <option value="year">{{ t('This year') }}</option>
                </select>
            </div>
        </div>

        <p v-if="message" class="mb-3 rounded-lg px-4 py-3" :class="message.tone === 'success' ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'">{{ message.text }}</p>

        <div v-if="selectable.length" class="mb-2 flex items-center" :class="exportList ? 'flex-wrap gap-x-3 gap-y-1' : 'gap-3'">
            <label class="flex min-h-12 cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" class="size-5 accent-accent" data-flow="select-all" :checked="allSelected" @change="toggleAll">
                {{ exportList ? t('Select all') : t('Select all pending') }}
            </label>
            <span class="flex-1" />
            <template v-if="exportList">
                <template v-if="selected.size">
                    <span class="text-sm text-muted" data-flow="export-count">{{ tc(':count orders selected', selected.size) }}</span>
                    <div class="flex w-full gap-2 sm:w-auto">
                        <UiButton class="flex-1 sm:flex-none" data-flow="export-csv" :disabled="downloading" @click="download('csv')">{{ t('CSV') }}</UiButton>
                        <UiButton class="flex-1 sm:flex-none" variant="secondary" data-flow="export-xlsx" :disabled="downloading" @click="download('xlsx')">{{ t('Excel') }}</UiButton>
                    </div>
                </template>
            </template>
            <UiButton v-else-if="selected.size" data-flow="bulk-approve" @click="bulkOpen = true">{{ t('Approve :count', { count: selected.size }) }}</UiButton>
        </div>
        <button v-if="pendingExport" type="button" class="mb-3 flex min-h-12 w-full items-center justify-center rounded-lg bg-accent px-4 font-medium text-white" @click="openPendingExport">
            {{ t('Open the file') }}
        </button>

        <!-- Phone: cards -->
        <ul class="space-y-2 lg:hidden">
            <li v-for="order in orders" :key="order.uuid" class="flex items-stretch gap-2">
                <label v-if="canSelect(order)" class="flex w-11 shrink-0 cursor-pointer items-center justify-center">
                    <input type="checkbox" class="size-5 accent-accent" data-flow="order-select" :data-order="order.number" :checked="selected.has(order.uuid)" :aria-label="t('Select :number', { number: order.number })" @change="toggle(order)">
                </label>
                <Link :href="`/orders/${order.uuid}`" data-flow="order-card" :data-order="order.number" class="block min-w-0 flex-1 rounded-xl border border-line bg-surface p-4 hover:bg-page">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="flex-1 font-semibold whitespace-nowrap">{{ order.number }}</span>
                        <OrderStatusChip :status="order.display_status" :label="order.status_label" />
                    </div>
                    <p class="mt-1 line-clamp-2 break-words">{{ order.client.company_name }}</p>
                    <p v-if="order.stock_conflicts?.length" class="mt-1"><StatusChip tone="danger" wrap data-flow="stock-conflict" :data-order="order.number">{{ t('Insufficient stock') }} · {{ shortRefs(order) }}</StatusChip></p>
                    <div class="mt-1 flex items-end gap-2 text-sm text-muted">
                        <span class="min-w-0 flex-1 break-words">{{ formatDate(order.created_at) }} · {{ order.author.name }}</span>
                        <span class="font-medium text-ink tabular-nums">{{ formatMoney(order.total_ttc) }}</span>
                    </div>
                </Link>
            </li>
        </ul>

        <!-- Desktop: table -->
        <table v-if="orders.length" class="hidden w-full table-fixed overflow-hidden rounded-xl border border-line bg-surface text-sm lg:table">
            <colgroup>
                <col class="w-14">
                <col class="w-32">
                <col class="w-[18%]">
                <col>
                <col class="w-16">
                <col class="w-28">
                <col class="w-44">
            </colgroup>
            <thead class="bg-page text-left text-muted">
                <tr>
                    <th class="px-1 py-1">
                        <label v-if="selectable.length" class="flex size-11 cursor-pointer items-center justify-center">
                            <input type="checkbox" class="size-5 accent-accent" :checked="allSelected" :aria-label="exportList ? t('Select all') : t('Select all pending')" @change="toggleAll">
                        </label>
                    </th>
                    <th class="px-3 py-3 font-medium">{{ t('Number') }}</th>
                    <th class="px-3 py-3 font-medium">{{ t('Author') }}</th>
                    <th class="px-3 py-3 font-medium">{{ t('Client') }}</th>
                    <th class="px-3 py-3 text-right font-medium">{{ t('Quantities') }}</th>
                    <th class="px-3 py-3 text-right font-medium">{{ t('Total TTC') }}</th>
                    <th class="px-3 py-3 font-medium">{{ t('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="order in orders" :key="order.uuid" class="cursor-pointer hover:bg-page" data-flow="order-row" :data-order="order.number" @click="router.visit(`/orders/${order.uuid}`)">
                    <td class="px-1 py-1" @click.stop>
                        <label v-if="canSelect(order)" class="flex size-11 cursor-pointer items-center justify-center">
                            <input type="checkbox" class="size-5 accent-accent" data-flow="order-select" :data-order="order.number" :checked="selected.has(order.uuid)" :aria-label="t('Select :number', { number: order.number })" @change="toggle(order)">
                        </label>
                    </td>
                    <td class="px-3 py-3">
                        <Link :href="`/orders/${order.uuid}`" class="font-medium whitespace-nowrap" @click.stop>{{ order.number }}</Link>
                        <span class="block text-muted">{{ formatDate(order.created_at) }}</span>
                    </td>
                    <td class="px-3 py-3 break-words">{{ order.author.name }}</td>
                    <td class="px-3 py-3">
                        <span class="line-clamp-2 break-words">{{ order.client.company_name }} <span class="text-muted">{{ order.client.town }}</span></span>
                        <StatusChip v-if="order.stock_conflicts?.length" tone="danger" wrap class="mt-1" data-flow="stock-conflict" :data-order="order.number">{{ t('Insufficient stock') }} · {{ shortRefs(order) }}</StatusChip>
                    </td>
                    <td class="px-3 py-3 text-right tabular-nums">{{ formatNumber(order.total_quantity) }}</td>
                    <td class="px-3 py-3 text-right whitespace-nowrap tabular-nums">{{ formatMoney(order.total_ttc) }}</td>
                    <!-- The chip shows the status only; the delivery note goes under it. -->
                    <td class="px-3 py-3">
                        <OrderStatusChip :status="order.display_status" :label="fileMode ? order.status_label : order.display_status === 'failed' ? t('order.label.failed') : t(`order.status.${order.display_status}`)" />
                        <span v-if="order.evoliz_delivery_number" class="mt-1 block text-muted">{{ order.evoliz_delivery_number }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="!loading && orders.length === 0" class="rounded-xl border border-dashed border-line p-6 text-center text-muted">{{ t('No order yet.') }}</p>

        <UiButton v-if="page < lastPage" variant="secondary" block class="mt-4" :disabled="loading" @click="load(false)">
            {{ loading ? t('Loading…') : t('Load more') }}
        </UiButton>

        <ConfirmDialog
            :open="bulkOpen"
            :title="tc('Approve :count orders?', selected.size)"
            :message="bulkMessage"
            :confirm-label="fileMode ? t('Approve') : t('Create the delivery notes')"
            :busy="bulkBusy"
            @confirm="bulkApprove"
            @cancel="bulkOpen = false"
        />

        <ConfirmDialog
            :open="markOpen"
            :title="tc('Mark this order as exported?|Mark these :count orders as exported?', fileUuids.length)"
            :message="tc('Its quantities leave the stock, free lines included, and it counts as a sale. If the file is not right, leave it as it is. It can be put back from its page.|Their quantities leave the stock, free lines included, and they count as sales. If the file is not right, leave them as they are. An order can be put back from its page.', fileUuids.length)"
            :confirm-label="tc('Mark as exported', fileUuids.length)"
            :busy="markBusy"
            @confirm="markExported"
            @cancel="markOpen = false"
        />
    </AppLayout>
</template>
