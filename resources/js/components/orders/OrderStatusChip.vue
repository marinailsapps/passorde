<script setup lang="ts">
import { computed } from 'vue';

import StatusChip from '@/components/StatusChip.vue';
import { t } from '@/lib/i18n';
import type { DisplayStatus, LocalState } from '@/types';

/**
 * `status` is the viewer's display_status; `label` the server's status_label.
 * A phone-side state wins: grey "En attente de réseau" while the outbox holds
 * the order, red "Non envoyée" once the server refused it.
 */
const props = defineProps<{ status: DisplayStatus; label: string; localState?: LocalState }>();

const tones = { local: 'neutral', pending: 'warning', approved: 'info', sent: 'success', rejected: 'danger', failed: 'danger' } as const;

const chip = computed(() => {
    if (props.localState === 'queued') return { tone: 'neutral' as const, label: t('Waiting for network') };
    if (props.localState === 'unsent') return { tone: 'danger' as const, label: t('Not sent') };
    return { tone: tones[props.status], label: props.label };
});
</script>

<template>
    <StatusChip :tone="chip.tone">{{ chip.label }}</StatusChip>
</template>
