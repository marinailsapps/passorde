<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface TurnstileApi {
    render: (el: HTMLElement, options: Record<string, unknown>) => string;
    reset: (id: string) => void;
    remove: (id: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

defineProps<{ error?: string }>();

const token = defineModel<string>({ required: true });
const siteKey = computed(() => usePage().props.turnstileSiteKey);
const container = ref<HTMLElement | null>(null);
let widgetId: string | null = null;

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function loadScript(): Promise<TurnstileApi> {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    return new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT_URL}"]`);
        const script = existing ?? Object.assign(document.createElement('script'), { src: SCRIPT_URL, async: true });
        script.addEventListener('load', () => (window.turnstile ? resolve(window.turnstile) : reject()));
        script.addEventListener('error', () => reject());
        if (!existing) document.head.appendChild(script);
    });
}

onMounted(async () => {
    if (!siteKey.value || !container.value) return;

    const turnstile = await loadScript();
    widgetId = turnstile.render(container.value, {
        sitekey: siteKey.value,
        language: 'fr',
        callback: (value: string) => (token.value = value),
        'expired-callback': () => (token.value = ''),
        'error-callback': () => (token.value = ''),
    });
});

onBeforeUnmount(() => {
    if (widgetId && window.turnstile) window.turnstile.remove(widgetId);
});

/** Tokens are single-use: reset after each submission. */
function reset(): void {
    token.value = '';
    if (widgetId && window.turnstile) window.turnstile.reset(widgetId);
}

defineExpose({ reset });
</script>

<template>
    <div v-if="siteKey">
        <div ref="container" class="min-h-16" />
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
