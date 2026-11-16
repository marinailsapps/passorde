<script setup lang="ts">
import { useId } from 'vue';

defineProps<{
    label: string;
    options: { value: string; label: string }[];
    error?: string;
}>();

const model = defineModel<string>({ required: true });
const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="mb-1 block text-sm font-medium">{{ label }}</label>
        <select
            :id="id"
            v-model="model"
            class="block min-h-12 w-full rounded-lg border bg-surface px-3 text-base focus:border-accent focus:outline-2 focus:outline-accent/30"
            :class="error ? 'border-red-600' : 'border-field'"
        >
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
