<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { t } from '@/lib/i18n';

// PASSORDE_WRITES_ENABLED=false: approvals fail on purpose, nothing reaches Evoliz.
const page = usePage();
const testMode = computed(() => page.props.auth.user !== null && page.props.evolizWritesEnabled === false);
</script>

<template>
    <p v-if="testMode" class="bg-amber-100 px-4 py-1 text-center text-xs font-medium text-amber-900" role="status" data-flow="test-mode">
        {{ t('Test mode — nothing is sent to Evoliz') }}
    </p>
</template>
