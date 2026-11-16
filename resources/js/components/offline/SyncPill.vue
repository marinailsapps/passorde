<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import UiButton from '@/components/UiButton.vue';
import { formatAge } from '@/lib/format';
import { t, tc } from '@/lib/i18n';
import { isOffline, kickOutbox, offline, refreshMirror } from '@/offline/runtime';

/**
 * Top-bar state of offline mode: "Hors ligne · 2 en attente", "Envoi…",
 * "En ligne · synchronisé", "Session expirée". Tapping it shows the age of the
 * local data and a "Synchroniser" button.
 */
const open = ref(false);
// Re-render the data age every 30 s.
const tick = ref(0);
const timer = setInterval(() => tick.value++, 30_000);
onBeforeUnmount(() => clearInterval(timer));

const state = computed(() => {
    if (offline.authExpired) return { tone: 'danger', label: t('Session expired — sign in again') };
    if (isOffline()) return { tone: 'neutral', label: offline.pending ? t('Offline · :count waiting', { count: offline.pending }) : t('Offline') };
    if (offline.sending) return { tone: 'info', label: t('Sending…') };
    if (offline.pending) return { tone: 'info', label: t('Online · :count waiting', { count: offline.pending }) };
    if (offline.unsent) return { tone: 'danger', label: tc(':count not sent', offline.unsent) };
    return { tone: 'success', label: t('Online · synced') };
});

const age = computed(() => {
    void tick.value;
    return offline.syncedAt ? t('Data: :age', { age: formatAge(offline.syncedAt) }) : t('Data: never synced');
});

function syncNow(): void {
    void kickOutbox(true);
    void refreshMirror();
}
</script>

<template>
    <button
        type="button"
        data-flow="sync-pill"
        :data-state="state.tone"
        class="flex min-h-10 min-w-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium"
        :class="{
            neutral: 'border-slate-300 bg-slate-100 text-slate-700',
            info: 'border-sky-200 bg-sky-50 text-sky-800',
            success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
            danger: 'border-red-200 bg-red-50 text-red-800',
        }[state.tone]"
        :aria-label="`${state.label}. ${age}`"
        @click="open = true"
    >
        <span class="size-2 shrink-0 rounded-full" :class="{ neutral: 'bg-slate-400', info: 'bg-sky-500', success: 'bg-emerald-500', danger: 'bg-red-500' }[state.tone]" aria-hidden="true" />
        <span class="truncate">{{ state.label }}</span>
    </button>

    <BottomSheet :open="open" :title="t('Synchronisation')" @close="open = false">
        <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
            <dt class="text-muted">{{ t('Network') }}</dt>
            <dd>{{ isOffline() ? t('Offline') : t('Online') }}</dd>
            <dt class="text-muted">{{ t('Orders waiting to be sent') }}</dt>
            <dd class="tabular-nums">{{ offline.pending }}</dd>
            <template v-if="offline.unsent">
                <dt class="text-muted">{{ t('Orders not sent (to fix)') }}</dt>
                <dd class="text-red-700 tabular-nums">{{ offline.unsent }}</dd>
            </template>
            <dt class="text-muted">{{ t('Clients, catalogue and stock') }}</dt>
            <dd>{{ age }}</dd>
        </dl>
        <p v-if="offline.authExpired" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">
            {{ t('Your session has expired. Sign in again: the waiting orders are kept and will be sent.') }}
        </p>
        <div class="mt-4 grid gap-2">
            <UiButton v-if="offline.authExpired" href="/login">{{ t('Sign in') }}</UiButton>
            <UiButton :disabled="isOffline() || offline.syncing || offline.sending" @click="syncNow">
                {{ offline.syncing ? t('Synchronisation in progress…') : t('Synchronise now') }}
            </UiButton>
            <UiButton variant="secondary" href="/orders" @click="open = false">{{ t('My orders') }}</UiButton>
        </div>
    </BottomSheet>
</template>
