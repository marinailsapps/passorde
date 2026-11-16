<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import StatusChip from '@/components/StatusChip.vue';
import { formatDay, formatMoney, formatMonth, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import { clientHistory, historyMirrored } from '@/offline/runtime';
import type { HistoryEntry, HistoryStatus } from '@/types';

/*
 * "Historique" of the order form: every purchase of the client, newest first,
 * from Evoliz's delivery notes and the app's orders not in Evoliz yet. Offline
 * it comes from the phone's copy (docs/API.md "Sales history").
 */
const props = defineProps<{ clientId: number; open: boolean }>();

const entries = ref<HistoryEntry[]>([]);
const loading = ref(false);
const local = ref(false);
const complete = ref(true);

watch(
    () => [props.open, props.clientId] as const,
    async ([open, clientId]) => {
        if (!open) return;
        loading.value = true;
        try {
            const result = await clientHistory(clientId);
            entries.value = result.entries;
            local.value = result.local;
            complete.value = !result.local || (await historyMirrored());
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const months = computed(() => {
    const groups: { month: string; entries: HistoryEntry[] }[] = [];
    for (const entry of entries.value) {
        const month = entry.date.slice(0, 7);
        if (groups.at(-1)?.month !== month) groups.push({ month, entries: [] });
        groups.at(-1)!.entries.push(entry);
    }
    return groups;
});

const chips: Record<HistoryStatus, { tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info'; label: string }> = {
    pending: { tone: 'warning', label: 'history.pending' },
    delivered: { tone: 'info', label: 'history.delivered' },
    invoiced: { tone: 'success', label: 'history.invoiced' },
    rejected: { tone: 'danger', label: 'history.rejected' },
};
</script>

<template>
    <div data-flow="client-history">
        <p v-if="local" class="mb-3 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">
            {{ complete ? (isFileMode() ? t('Offline: history saved on this phone (your own orders only).') : t('Offline: history saved on this phone (your own orders not yet in Evoliz only).')) : t('Offline: the full history is still loading on this phone; what is shown may be incomplete.') }}
        </p>
        <p v-if="loading && entries.length === 0" class="py-6 text-center text-muted">{{ t('Loading…') }}</p>
        <p v-else-if="entries.length === 0" class="py-6 text-center text-muted">{{ t('No purchase for this client yet.') }}</p>

        <section v-for="group in months" :key="group.month" class="mb-4">
            <h3 class="sticky top-0 z-10 -mx-4 bg-surface px-4 py-1 text-sm font-semibold text-muted">{{ formatMonth(group.month) }}</h3>
            <ul class="space-y-2">
                <li v-for="entry in group.entries" :key="entry.key" class="rounded-xl border border-line p-3 text-sm" data-flow="history-entry">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-semibold tabular-nums">{{ formatDay(entry.date) }}</span>
                        <span class="text-muted">{{ [entry.delivery_number, entry.order_number].filter(Boolean).join(' · ') }}</span>
                        <span class="flex-1" />
                        <StatusChip :tone="chips[entry.status].tone">{{ t(chips[entry.status].label) }}</StatusChip>
                    </div>
                    <p class="mt-0.5 text-muted">{{ entry.seller }}</p>
                    <ul class="mt-2 space-y-0.5">
                        <li v-for="(line, index) in entry.lines" :key="index" class="flex gap-2" :class="{ 'text-muted': line.is_free }">
                            <span class="min-w-10 shrink-0 text-right whitespace-nowrap tabular-nums">{{ formatNumber(line.quantity) }} ×</span>
                            <span class="min-w-0 flex-1 break-words">
                                {{ line.reference ?? line.designation }}
                                <span v-if="line.is_free" class="ml-1 rounded bg-emerald-50 px-1 text-xs text-emerald-800">{{ t('offered') }}</span>
                            </span>
                            <span v-if="!line.is_free" class="shrink-0 text-right tabular-nums">
                                <span class="text-xs text-muted">{{ formatMoney(line.unit_price_ht) }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-if="entry.comment" class="mt-2 text-muted italic">{{ entry.comment }}</p>
                    <p class="mt-2 flex justify-end gap-3 border-t border-line pt-1 tabular-nums">
                        <span class="text-muted">{{ formatMoney(entry.totals.ht) }} {{ t('HT') }}</span>
                        <span class="font-semibold">{{ formatMoney(entry.totals.ttc) }} {{ t('TTC') }}</span>
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
