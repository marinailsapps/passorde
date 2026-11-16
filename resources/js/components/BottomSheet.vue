<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';

import { t } from '@/lib/i18n';

// full: the whole screen on a phone (long content such as a client's history).
const props = defineProps<{ open: boolean; title: string; full?: boolean }>();
const emit = defineEmits<{ close: [] }>();

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') emit('close');
}

watch(
    () => props.open,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) window.addEventListener('keydown', onKey);
        else window.removeEventListener('keydown', onKey);
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-40 flex items-end justify-center bg-slate-900/40 lg:items-center" @click.self="emit('close')">
            <div
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="w-full overflow-y-auto bg-surface p-4 shadow-xl lg:rounded-2xl"
                :class="full ? 'h-dvh lg:h-auto lg:max-h-[90dvh] lg:max-w-2xl' : 'max-h-[85dvh] rounded-t-2xl lg:max-w-lg'"
            >
                <div class="mb-3 flex items-center gap-2">
                    <h2 class="flex-1 text-lg font-semibold">{{ title }}</h2>
                    <button type="button" class="inline-flex size-12 items-center justify-center rounded-lg text-muted hover:bg-page" :aria-label="t('Close')" @click="emit('close')">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                    </button>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
