<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import UiButton from '@/components/UiButton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';

/**
 * Shown for a route that needs the server while the network is down
 * (reason "route"), for an order the phone does not have ("order"), for the
 * sales report in file mode or with a session older than the catalogue
 * settings ("sales"), or before anyone signed in on this
 * phone with a network ("session").
 */
const props = withDefaults(defineProps<{ reason?: 'route' | 'order' | 'session' | 'sales' }>(), { reason: 'route' });

const signedIn = computed(() => usePage().props.auth?.user != null);
const message = computed(
    () =>
        ({
            route: t('This page needs the network. Orders can still be entered offline.'),
            order: t('This order is not on this phone. It will show once the network is back.'),
            session: t('Sign in once with a network connection to use the app offline.'),
            sales: t('The sales report is not available offline: it shows once the network is back. Orders can still be entered offline.'),
        })[props.reason],
);

function retry(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="t('Offline')" />
    <component :is="signedIn ? AppLayout : 'div'" :class="signedIn ? '' : 'px-4 py-10'">
        <div class="mx-auto max-w-md py-8 text-center">
            <p class="text-4xl" aria-hidden="true">⌁</p>
            <h1 class="mt-2 text-xl font-semibold">{{ t('You are offline') }}</h1>
            <p class="mt-2 text-muted">{{ message }}</p>
            <div class="mt-6 grid gap-3">
                <UiButton block @click="retry">{{ t('Retry') }}</UiButton>
                <UiButton v-if="signedIn" variant="secondary" block href="/orders/new">{{ t('New order') }}</UiButton>
                <UiButton v-if="signedIn" variant="ghost" block href="/orders">{{ t('My orders') }}</UiButton>
            </div>
        </div>
    </component>
</template>
