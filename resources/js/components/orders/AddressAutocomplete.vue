<script setup lang="ts">
import { ref, useId, watch } from 'vue';

import { t } from '@/lib/i18n';

/** Address search on the Base Adresse Nationale, called from the browser. */
interface BanFeature {
    properties: { label: string; name: string; postcode: string; city: string };
}

defineProps<{ error?: string }>();
const model = defineModel<string>({ required: true });
const emit = defineEmits<{ pick: [address: { address_line: string; postcode: string; town: string }] }>();

const id = useId();
const suggestions = ref<BanFeature[]>([]);
const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
let skipNext = false;

watch(model, (value) => {
    clearTimeout(timer);
    if (skipNext) {
        skipNext = false;
        return;
    }
    if (value.trim().length < 3) {
        suggestions.value = [];
        return;
    }
    timer = setTimeout(() => search(value), 300);
});

async function search(q: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    try {
        const url = `https://api-adresse.data.gouv.fr/search/?q=${encodeURIComponent(q)}&limit=5`;
        const response = await fetch(url, { signal: controller.signal });
        const data = (await response.json()) as { features?: BanFeature[] };
        suggestions.value = data.features ?? [];
        open.value = suggestions.value.length > 0;
    } catch {
        // Offline or aborted: the fields stay editable by hand.
        suggestions.value = [];
    }
}

function closeSoon(): void {
    setTimeout(() => (open.value = false), 150);
}

function pick(feature: BanFeature): void {
    skipNext = true;
    model.value = feature.properties.name;
    open.value = false;
    emit('pick', { address_line: feature.properties.name, postcode: feature.properties.postcode, town: feature.properties.city.toUpperCase() });
}
</script>

<template>
    <div class="relative">
        <label :for="id" class="mb-1 block text-sm font-medium">{{ t('Address') }}</label>
        <input
            :id="id"
            v-model="model"
            type="text"
            autocomplete="off"
            :placeholder="t('Start typing, then pick the address')"
            class="block min-h-12 w-full rounded-lg border bg-surface px-3 text-base focus:border-accent focus:outline-2 focus:outline-accent/30"
            :class="error ? 'border-red-600' : 'border-field'"
            @focus="open = suggestions.length > 0"
            @blur="closeSoon"
        >
        <ul v-if="open" data-flow="address-suggestions" class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-line bg-surface shadow-lg">
            <li v-for="feature in suggestions" :key="feature.properties.label">
                <button type="button" class="flex min-h-12 w-full items-center px-3 text-left hover:bg-page" @mousedown.prevent="pick(feature)">
                    {{ feature.properties.label }}
                </button>
            </li>
        </ul>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
