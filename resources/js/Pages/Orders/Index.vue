<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';

import PageHeader from '@/components/PageHeader.vue';
import SearchField from '@/components/SearchField.vue';
import UiButton from '@/components/UiButton.vue';
import OrderStatusChip from '@/components/orders/OrderStatusChip.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiGet } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { LocalOrder } from '@/offline/db';
import { normalise } from '@/offline/search';
import { isOffline, localOrders, offline } from '@/offline/runtime';
import type { OrderStatus, OrderSummary } from '@/types';

type Period = 'all' | 'month' | '30days' | 'year';

const filters = reactive<{ status: OrderStatus | ''; period: Period; q: string }>({ status: '', period: 'all', q: '' });
const orders = ref<OrderSummary[]>([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const failed = ref(false);

const fileMode = isFileMode();

const statuses: { value: OrderStatus | ''; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'order.status.pending' },
    { value: 'approved', label: 'order.status.approved' },
    { value: 'sent', label: 'order.status.sent' },
    { value: 'rejected', label: 'order.status.rejected' },
].filter((item) => !fileMode || item.value !== 'sent') as { value: OrderStatus | ''; label: string }[];

const iso = (date: Date): string => date.toLocaleDateString('sv-SE', { timeZone: 'Europe/Paris' });

function range(period: Period): { from?: string } {
    const now = new Date();
    if (period === 'month') return { from: iso(new Date(now.getFullYear(), now.getMonth(), 1)) };
    if (period === '30days') return { from: iso(new Date(now.getTime() - 30 * 86400000)) };
    if (period === 'year') return { from: iso(new Date(now.getFullYear(), 0, 1)) };
    return {};
}

/* Orders on the phone: waiting for the network or refused by the server, always on top. */

const local = ref<LocalOrder[]>([]);

function summaryOf(order: LocalOrder): OrderSummary {
    return {
        uuid: order.uuid,
        number: order.number,
        status: order.status === 'local' ? 'pending' : order.status,
        display_status: order.display_status,
        status_label: order.status_label,
        author: order.author,
        can_approve: false,
        created_at: order.created_at,
        client: { id: order.client.id, company_name: order.client.company_name, town: order.client.town },
        total_quantity: order.totals.quantity,
        total_ttc: order.totals.ttc,
        evoliz_delivery_number: order.evoliz_delivery_number,
        can_edit: order.can_edit,
        can_delete: order.can_delete,
        local_state: order.local_state,
        local_error: order.local_error,
    };
}

const waiting = computed(() =>
    local.value
        .filter((order) => order.local_state === 'queued' || order.local_state === 'unsent')
        .sort((a, b) => b.created_at.localeCompare(a.created_at))
        .map(summaryOf),
);
const waitingIds = computed(() => new Set(waiting.value.map((order) => order.uuid)));
const listed = computed(() => orders.value.filter((order) => !waitingIds.value.has(order.uuid)));

/** Offline: the same filters over the phone's copy of the user's orders. */
function filterLocally(): OrderSummary[] {
    const from = range(filters.period).from;
    const words = normalise(filters.q).split(/\s+/).filter(Boolean);
    return local.value
        // Orders still on their way are listed above, under "Sur ce téléphone".
        .filter((order) => order.local_state !== 'queued' && order.local_state !== 'unsent')
        .filter((order) => !filters.status || (filters.status === 'approved' ? ['approved', 'failed'].includes(order.status) : order.status === filters.status))
        .filter((order) => !from || iso(new Date(order.created_at)) >= from)
        .filter((order) => filters.q.trim().length < 2 || words.every((word) => normalise(`${order.client.company_name} ${order.client.town ?? ''}`).includes(word)))
        .sort((a, b) => b.created_at.localeCompare(a.created_at))
        .map(summaryOf);
}

async function load(reset: boolean): Promise<void> {
    loading.value = true;
    failed.value = false;
    const next = reset ? 1 : page.value + 1;
    local.value = await localOrders();

    if (isOffline()) {
        orders.value = filterLocally();
        page.value = lastPage.value = 1;
        total.value = orders.value.length;
        loading.value = false;
        return;
    }

    try {
        const response = await apiGet<{ data: OrderSummary[]; meta: { current_page: number; last_page: number; total: number } }>('/api/orders', {
            status: filters.status,
            q: filters.q.trim().length >= 2 ? filters.q : undefined,
            page: next,
            ...range(filters.period),
        });
        orders.value = reset ? response.data : [...orders.value, ...response.data];
        page.value = response.meta.current_page;
        lastPage.value = response.meta.last_page;
        total.value = response.meta.total;
    } catch {
        // The server is out of reach after all: show the phone's copy.
        orders.value = filterLocally();
        total.value = orders.value.length;
        page.value = lastPage.value = 1;
        failed.value = offline.reachable;
    } finally {
        loading.value = false;
    }
}

function setStatus(status: OrderStatus | ''): void {
    filters.status = status;
    load(true);
}

onMounted(() => load(true));
// An order left the outbox (its number appeared): it now belongs in the server list.
watch(
    () => offline.revision,
    async () => {
        const before = waitingIds.value;
        local.value = await localOrders();
        const landed = [...before].some((uuid) => !waitingIds.value.has(uuid));
        if (landed && !isOffline()) await load(true);
    },
);
watch(isOffline, () => load(true));
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('My orders')" back="/">
            <template #actions>
                <UiButton href="/orders/new">{{ t('New') }}</UiButton>
            </template>
        </PageHeader>

        <div class="sticky top-[var(--header-height,3.5rem)] z-[5] -mx-4 mb-3 space-y-2 border-b border-line bg-page/95 px-4 pt-1 pb-3 backdrop-blur">
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" :aria-label="t('Status')">
                <button
                    v-for="item in statuses"
                    :key="item.value"
                    type="button"
                    class="min-h-10 shrink-0 rounded-full border px-4 text-sm"
                    :class="filters.status === item.value ? 'border-accent bg-accent text-white' : 'border-line bg-surface'"
                    :aria-pressed="filters.status === item.value"
                    @click="setStatus(item.value)"
                >
                    {{ t(item.label) }}
                </button>
            </div>
            <div class="grid grid-cols-[1fr_auto] gap-2">
                <SearchField v-model="filters.q" :label="t('Client')" @search="load(true)" />
                <select v-model="filters.period" class="min-h-12 rounded-lg border border-field bg-surface px-3" :aria-label="t('Period')" @change="load(true)">
                    <option value="all">{{ t('All dates') }}</option>
                    <option value="month">{{ t('This month') }}</option>
                    <option value="30days">{{ t('Last 30 days') }}</option>
                    <option value="year">{{ t('This year') }}</option>
                </select>
            </div>
        </div>

        <!-- On this phone, not on the server yet (or refused) -->
        <section v-if="waiting.length" class="mb-4" data-flow="on-this-phone">
            <h2 class="mb-2 text-sm font-medium text-muted">{{ t('On this phone') }}</h2>
            <ul class="space-y-2">
                <li v-for="order in waiting" :key="order.uuid">
                    <Link :href="`/orders/${order.uuid}`" class="block rounded-xl border bg-surface p-4 hover:bg-page" :class="order.local_state === 'unsent' ? 'border-red-300' : 'border-dashed border-slate-300'">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="flex-1 font-semibold whitespace-nowrap">{{ order.number || t('Local draft') }}</span>
                            <OrderStatusChip :status="order.display_status" :label="order.status_label" :local-state="order.local_state" />
                        </div>
                        <p class="mt-1 line-clamp-2 break-words">{{ order.client.company_name }}</p>
                        <p v-if="order.local_error" class="mt-1 text-sm text-red-800">{{ t('Not sent: :message', { message: order.local_error }) }}</p>
                        <div class="mt-1 flex text-sm text-muted">
                            <span class="flex-1">{{ formatDate(order.created_at) }}</span>
                            <span class="font-medium text-ink tabular-nums">{{ formatMoney(order.total_ttc) }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <p v-if="isOffline()" class="mb-2 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ t('Offline: orders as of the last synchronisation.') }}</p>
        <p class="mb-2 text-sm text-muted">{{ tc(':count orders', total) }}</p>

        <!-- Phone: cards -->
        <ul class="space-y-2 lg:hidden">
            <li v-for="order in listed" :key="order.uuid">
                <Link :href="`/orders/${order.uuid}`" class="block rounded-xl border border-line bg-surface p-4 hover:bg-page">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="flex-1 font-semibold whitespace-nowrap">{{ order.number }}</span>
                        <OrderStatusChip :status="order.display_status" :label="order.status_label" />
                    </div>
                    <p class="mt-1 line-clamp-2 break-words">{{ order.client.company_name }}</p>
                    <div class="mt-1 flex text-sm text-muted">
                        <span class="flex-1">{{ formatDate(order.created_at) }}</span>
                        <span class="font-medium text-ink tabular-nums">{{ formatMoney(order.total_ttc) }}</span>
                    </div>
                </Link>
            </li>
        </ul>

        <!-- Desktop: table -->
        <table v-if="listed.length" class="hidden w-full overflow-hidden rounded-xl border border-line bg-surface text-sm lg:table">
            <thead class="bg-page text-left text-muted">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ t('Number') }}</th>
                    <th class="px-4 py-3 font-medium">{{ t('Date') }}</th>
                    <th class="px-4 py-3 font-medium">{{ t('Client') }}</th>
                    <th class="px-4 py-3 font-medium">{{ t('Status') }}</th>
                    <th v-if="!fileMode" class="px-4 py-3 font-medium">{{ t('Delivery note') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ t('Total TTC') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="order in listed" :key="order.uuid" class="cursor-pointer hover:bg-page" @click="router.visit(`/orders/${order.uuid}`)">
                    <td class="px-4 py-3 font-medium"><Link :href="`/orders/${order.uuid}`">{{ order.number }}</Link></td>
                    <td class="px-4 py-3">{{ formatDate(order.created_at) }}</td>
                    <td class="px-4 py-3">{{ order.client.company_name }} <span class="text-muted">{{ order.client.town }}</span></td>
                    <!-- The delivery note has its own column: the chip shows the status only. -->
                    <td class="px-4 py-3"><OrderStatusChip :status="order.display_status" :label="order.display_status === 'sent' ? t('order.status.sent') : order.status_label" /></td>
                    <td v-if="!fileMode" class="px-4 py-3 text-muted">{{ order.evoliz_delivery_number ?? '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(order.total_ttc) }}</td>
                </tr>
            </tbody>
        </table>

        <p v-if="!loading && !failed && listed.length === 0 && waiting.length === 0" class="rounded-xl border border-dashed border-line p-6 text-center text-muted">{{ t('No order yet.') }}</p>
        <p v-if="failed" class="rounded-lg bg-red-50 px-4 py-3 text-red-900">{{ t('The orders could not be loaded. Check the connection.') }}</p>

        <UiButton v-if="page < lastPage && !isOffline()" variant="secondary" block class="mt-4" :disabled="loading" @click="load(false)">
            {{ loading ? t('Loading…') : t('Load more') }}
        </UiButton>
    </AppLayout>
</template>
