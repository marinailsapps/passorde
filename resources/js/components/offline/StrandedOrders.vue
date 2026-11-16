<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import ConfirmDialog from '@/components/ConfirmDialog.vue';
import UiButton from '@/components/UiButton.vue';
import { formatDateTime, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { retryStranded, type StrandedOrders, strandedOrders, wipePhone } from '@/offline/runtime';

/*
 * Login page, after the server ended this phone's session (deactivated account,
 * password changed elsewhere) while orders were still waiting: lists them,
 * keeps trying to send them (they go once that user signs in again) and lets
 * the person wipe the phone (docs/RUNBOOK.md "Lost or stolen phone").
 */
const RETRY_MS = 2 * 60_000;

const stranded = ref<StrandedOrders | null>(null);
const confirming = ref(false);
const wiping = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

async function load(): Promise<void> {
    stranded.value = await strandedOrders();
}

async function retry(): Promise<void> {
    if (document.visibilityState !== 'visible') return;
    await retryStranded().catch(() => null);
    await load();
}

async function wipe(): Promise<void> {
    wiping.value = true;
    await wipePhone();
    wiping.value = false;
    confirming.value = false;
    stranded.value = null;
}

onMounted(async () => {
    await load();
    timer = setInterval(() => void retry(), RETRY_MS);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section v-if="stranded" data-flow="stranded-orders" class="mb-4 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950" role="alert">
        <h2 class="font-semibold">{{ t('Orders not sent') }}</h2>
        <p class="mt-1 text-sm">
            {{ t('The session of :name was closed before these orders reached the office. They will be sent as soon as :name signs in again on this phone.', { name: stranded.userName }) }}
        </p>
        <ul class="mt-3 divide-y divide-red-200 rounded-lg border border-red-200 bg-surface text-sm text-ink">
            <li v-for="order in stranded.orders" :key="order.uuid" class="flex items-baseline gap-3 px-3 py-2">
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">{{ order.client?.company_name ?? '—' }}</span>
                    <span class="block text-muted">
                        {{ order.number || t('Local draft') }}<template v-if="order.created_at"> · {{ formatDateTime(order.created_at) }}</template>
                        <template v-if="order.deleted_at"> · {{ t('deletion') }}</template>
                    </span>
                </span>
                <span v-if="order.totals" class="shrink-0 tabular-nums">{{ formatMoney(order.totals.ttc) }}</span>
            </li>
        </ul>
        <UiButton variant="danger" block class="mt-3" data-flow="wipe-phone" @click="confirming = true">{{ t('Erase the data on this phone') }}</UiButton>

        <ConfirmDialog
            :open="confirming"
            :title="t('Erase the data on this phone?')"
            :message="t('The orders above will be lost, with the client directory and the catalogue stored on this phone. Do this if the phone is lost or will be used by someone else.')"
            :confirm-label="t('Erase')"
            danger
            :busy="wiping"
            @confirm="wipe"
            @cancel="confirming = false"
        />
    </section>
</template>
