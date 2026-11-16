<script setup lang="ts">
import { useId } from 'vue';

withDefaults(
    defineProps<{
        label: string;
        error?: string;
        type?: string;
        autocomplete?: string;
        inputmode?: 'text' | 'email' | 'tel' | 'numeric' | 'search' | 'decimal';
        placeholder?: string;
        hint?: string;
        required?: boolean;
    }>(),
    { error: undefined, type: 'text', autocomplete: 'off', inputmode: undefined, placeholder: undefined, hint: undefined, required: false },
);

const model = defineModel<string | null>({ required: true });
const id = useId();
</script>

<template>
    <div class="min-w-0">
        <label :for="id" class="mb-1 block text-sm font-medium">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            :type="type"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :placeholder="placeholder"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id}-error` : undefined"
            class="block min-h-12 w-full min-w-0 rounded-lg border bg-surface px-3 text-base focus:border-accent focus:outline-2 focus:outline-accent/30"
            :class="error ? 'border-red-600' : 'border-field'"
        >
        <p v-if="hint && !error" class="mt-1 text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
