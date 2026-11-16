<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { t } from '@/lib/i18n';

// File mode with a catalogue synced from Evoliz: most likely an instance that lost its keys.
const page = usePage();
const mismatch = computed(() => page.props.syncMismatch === true);
</script>

<template>
    <p v-if="mismatch" class="bg-red-700 px-4 py-1 text-center text-xs font-medium text-white" role="alert" data-flow="sync-mismatch">
        {{ t('File mode, with a catalogue from Evoliz: approved orders are not sent to Evoliz. Check EVOLIZ_ENABLED and the Evoliz keys.') }}
    </p>
</template>
