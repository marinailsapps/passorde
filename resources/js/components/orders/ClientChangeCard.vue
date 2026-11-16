<script setup lang="ts">
import { computed } from 'vue';

import CheckboxField from '@/components/CheckboxField.vue';
import StatusChip from '@/components/StatusChip.vue';
import { CORRECTABLE, fieldLabel } from '@/lib/clientCorrection';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { isFileMode } from '@/lib/syncMode';
import type { ClientChangeView } from '@/types';

/** The client correction saved with an order: old → new, its fate, and the operator's "Appliquer". */
const props = defineProps<{ change: ClientChangeView; canChoose?: boolean }>();
const apply = defineModel<boolean>('apply', { default: true });
const fileMode = isFileMode();

const rows = computed(() => CORRECTABLE.filter((field) => props.change.changes[field]).map((field) => ({ field, ...props.change.changes[field]! })));

const state = computed(() => {
    switch (props.change.status) {
        case 'applied':
            return { tone: 'success' as const, label: t('Applied') };
        case 'failed':
            return { tone: 'danger' as const, label: t('Refused by Evoliz') };
        case 'rejected':
            return { tone: 'neutral' as const, label: t('Left out') };
        case 'cancelled':
            return { tone: 'neutral' as const, label: t('Cancelled') };
        default:
            return { tone: 'warning' as const, label: t('Waiting for approval') };
    }
});
</script>

<template>
    <section class="rounded-xl border border-amber-300 bg-surface p-4" data-flow="client-change-card">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">{{ t('Client details changed') }}</h2>
            <StatusChip :tone="state.tone">{{ state.label }}</StatusChip>
        </div>
        <dl class="mt-3 divide-y divide-line text-sm">
            <div v-for="row in rows" :key="row.field" class="grid gap-1 py-2 sm:grid-cols-[9rem_1fr]">
                <dt class="text-muted">{{ fieldLabel(row.field) }}</dt>
                <dd class="min-w-0 break-words">
                    <span class="text-muted line-through">{{ row.from ?? '—' }}</span>
                    <span class="mx-1 text-muted" aria-hidden="true">→</span>
                    <span class="font-medium">{{ row.to ?? t('(removed)') }}</span>
                </dd>
            </div>
        </dl>
        <p v-if="change.status === 'failed' && change.error" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm break-words text-red-800">{{ change.error }}</p>
        <p v-if="change.status === 'applied' && change.applied_at" class="mt-2 text-sm text-muted">
            {{ t(fileMode ? 'Applied on :date.' : 'Sent to Evoliz on :date.', { date: formatDateTime(change.applied_at) }) }}
        </p>
        <p v-else-if="change.decided_by && change.status !== 'pending'" class="mt-2 text-sm text-muted">{{ change.decided_by }}</p>
        <CheckboxField v-if="canChoose" v-model="apply" class="mt-2" data-flow="client-change-apply" :label="fileMode ? t('Apply to the client when approving') : t('Apply to the client in Evoliz when approving')" />
    </section>
</template>
