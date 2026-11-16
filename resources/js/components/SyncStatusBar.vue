<script setup lang="ts">
import { router, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

import UiButton from '@/components/UiButton.vue';
import { formatAge } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SyncState } from '@/types';

const props = defineProps<{ sync: SyncState; action: string }>();

// Poll while a run is queued or running; web requests never call Evoliz themselves.
const { start, stop } = usePoll(3000, { only: ['sync', 'clients', 'articles', 'flash'] }, { autoStart: false });

watch(
    () => props.sync.active,
    (active) => (active ? start() : stop()),
    { immediate: true },
);

function run(): void {
    router.post(props.action, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface px-4 py-3">
        <div class="text-sm">
            <p v-if="sync.active" class="font-medium text-accent">{{ t('Synchronisation in progress…') }}</p>
            <p v-else>{{ t('Last sync: :age', { age: formatAge(sync.last_success_at) }) }}</p>
            <p v-if="!sync.active && sync.last?.status === 'failed'" class="text-red-700">{{ t('Last attempt failed: :error', { error: sync.last.error ?? '' }) }}</p>
        </div>
        <UiButton variant="secondary" :disabled="sync.active" @click="run">{{ t('Synchronise') }}</UiButton>
    </div>
</template>
