<script setup lang="ts" generic="T extends { id: number }">
import { ref, shallowRef, useId, watch } from 'vue';

import { apiGet } from '@/lib/api';
import { t } from '@/lib/i18n';

/**
 * Pick one record by searching an /api/…/search endpoint (online, ranked by the server).
 */
const props = defineProps<{
    label: string;
    endpoint: string;
    title: (item: T) => string;
    subtitle?: (item: T) => string | null;
    placeholder?: string;
    error?: string;
}>();

const model = defineModel<T | null>({ required: true });
const id = useId();
const query = ref('');
// shallowRef: the items are plain API rows, and a deep ref would change their type.
const results = shallowRef<T[]>([]);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let request = 0;

watch(query, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const mine = ++request;
        try {
            const { data } = await apiGet<{ data: T[] }>(props.endpoint, { q: value });
            if (mine === request) {
                results.value = data;
                failed.value = false;
            }
        } catch {
            if (mine === request) failed.value = true;
        }
    }, 250);
});

function pick(item: T): void {
    model.value = item;
    query.value = '';
    results.value = [];
}
</script>

<template>
    <div>
        <label :for="id" class="mb-1 block text-sm font-medium">{{ label }}</label>
        <div v-if="model" class="flex min-h-12 items-center gap-2 rounded-lg border border-field bg-surface px-3">
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ title(model) }}</span>
                <span v-if="subtitle?.(model)" class="block truncate text-sm text-muted">{{ subtitle(model) }}</span>
            </span>
            <button type="button" class="min-h-12 px-2 text-sm text-accent" @click="model = null">{{ t('Change') }}</button>
        </div>
        <template v-else>
            <input
                :id="id"
                v-model="query"
                type="search"
                autocomplete="off"
                :placeholder="placeholder"
                class="block min-h-12 w-full rounded-lg border bg-surface px-3 text-base focus:border-accent focus:outline-2 focus:outline-accent/30"
                :class="error ? 'border-red-600' : 'border-field'"
            >
            <ul v-if="results.length" class="mt-1 max-h-72 divide-y divide-line overflow-y-auto rounded-lg border border-line bg-surface shadow-sm">
                <li v-for="item in results" :key="item.id">
                    <button type="button" class="block min-h-12 w-full px-3 py-2 text-left hover:bg-page" @click="pick(item)">
                        <span class="block font-medium">{{ title(item) }}</span>
                        <span v-if="subtitle?.(item)" class="block text-sm break-words text-muted">{{ subtitle(item) }}</span>
                    </button>
                </li>
            </ul>
            <p v-else-if="failed" class="mt-1 text-sm text-red-700">{{ t('The search failed. Check the connection.') }}</p>
        </template>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
