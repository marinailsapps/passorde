<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { localPage } from '@/offline/runtime';

/**
 * The server does not know this order: it may have been confirmed on this
 * phone and not be sent yet. Show the phone's copy if there is one.
 */
const missing = ref(false);

onMounted(async () => {
    const page = await localPage(new URL(window.location.href));
    if (page && page.component !== 'Offline/Unavailable') {
        router.replace({ url: page.url, component: page.component, props: page.props });
    } else {
        missing.value = true;
    }
});
</script>

<template>
    <AppLayout>
        <p v-if="missing" class="rounded-xl border border-dashed border-line p-6 text-center text-muted">{{ t('Order not found.') }}</p>
        <p v-else class="p-6 text-center text-muted">{{ t('Loading…') }}</p>
    </AppLayout>
</template>
