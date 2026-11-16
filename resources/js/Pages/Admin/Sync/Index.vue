<script setup lang="ts">
import { router, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

import PageHeader from '@/components/PageHeader.vue';
import StatusChip from '@/components/StatusChip.vue';
import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SyncRun, SyncStatus } from '@/types';

interface PaytypeRow {
    id: number;
    evoliz_paytype_id: number;
    label: string;
    is_enabled: boolean;
}

const props = defineProps<{
    runs: SyncRun[];
    active: boolean;
    kinds: SyncRun['kind'][];
    paytypes: PaytypeRow[];
}>();

const { start, stop } = usePoll(3000, { only: ['runs', 'active', 'paytypes'] }, { autoStart: false });
watch(
    () => props.active,
    (active) => (active ? start() : stop()),
    { immediate: true },
);

const tones: Record<SyncStatus, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
    queued: 'neutral',
    running: 'info',
    succeeded: 'success',
    failed: 'danger',
    skipped: 'warning',
};

function run(kind: SyncRun['kind']): void {
    router.post(`/admin/sync/${kind}`, {}, { preserveScroll: true });
}

function togglePaytype(paytype: PaytypeRow): void {
    router.patch(`/admin/paytypes/${paytype.id}`, { is_enabled: !paytype.is_enabled }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout wide>
        <PageHeader :title="t('Synchronisation')" back="/" />

        <div class="mb-6 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <UiButton v-for="kind in kinds" :key="kind" variant="secondary" @click="run(kind)">
                {{ t(`Sync ${kind} now`) }}
            </UiButton>
        </div>

        <h2 class="mb-2 font-semibold">{{ t('Last 50 runs') }}</h2>
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="item in runs" :key="item.id" class="px-4 py-3 text-sm">
                <div class="flex items-center gap-2">
                    <span class="font-medium">{{ t(`kind.${item.kind}`) }}</span>
                    <StatusChip :tone="tones[item.status]">{{ t(`status.${item.status}`) }}</StatusChip>
                    <span class="flex-1" />
                    <span class="text-muted">{{ formatDateTime(item.created_at) }}</span>
                </div>
                <p class="mt-1 text-muted">
                    {{ t(`trigger.${item.trigger}`) }}<template v-if="item.user"> ({{ item.user }})</template>
                    <template v-if="item.duration !== null"> · {{ t(':seconds s', { seconds: item.duration }) }}</template>
                    <template v-if="item.status === 'succeeded'"> · {{ t(':seen seen, :changed changed', { seen: item.rows_seen, changed: item.rows_changed }) }}</template>
                </p>
                <p v-if="item.error" class="mt-1 break-words text-red-700">{{ item.error }}</p>
            </li>
            <li v-if="runs.length === 0" class="px-4 py-6 text-center text-muted">{{ t('No synchronisation yet.') }}</li>
        </ul>

        <h2 class="mt-8 mb-1 font-semibold">{{ t('Payment methods') }}</h2>
        <p class="mb-2 text-sm text-muted">{{ t('Evoliz has no active flag: choose which methods sales people can pick.') }}</p>
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
            <li v-for="paytype in paytypes" :key="paytype.id">
                <label class="flex min-h-12 cursor-pointer items-center gap-3 px-4">
                    <input type="checkbox" class="size-5 accent-accent" :checked="paytype.is_enabled" @change="togglePaytype(paytype)">
                    <span class="flex-1">{{ paytype.label }}</span>
                    <span class="text-xs text-muted">#{{ paytype.evoliz_paytype_id }}</span>
                </label>
            </li>
        </ul>
    </AppLayout>
</template>
