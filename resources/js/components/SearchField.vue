<script setup lang="ts">
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{ label: string; placeholder?: string; delay?: number }>(), {
    placeholder: undefined,
    delay: 300,
});

const model = defineModel<string>({ required: true });
const emit = defineEmits<{ search: [value: string] }>();
const local = ref(model.value);
let timer: ReturnType<typeof setTimeout> | undefined;

// Follow changes made by the parent (e.g. clearing the box after a pick).
watch(model, (value) => {
    if (value !== local.value) local.value = value;
});

watch(local, (value) => {
    clearTimeout(timer);
    if (value === model.value) return;
    timer = setTimeout(() => {
        model.value = value;
        emit('search', value);
    }, props.delay);
});
</script>

<template>
    <label class="block">
        <span class="sr-only">{{ label }}</span>
        <input
            v-model="local"
            type="search"
            inputmode="search"
            autocomplete="off"
            maxlength="100"
            :placeholder="placeholder ?? label"
            class="block min-h-12 w-full rounded-lg border border-field bg-surface px-3 text-base focus:border-accent focus:outline-2 focus:outline-accent/30"
        >
    </label>
</template>
