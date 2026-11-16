<script setup lang="ts">
defineProps<{
    label: string;
    error?: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <div>
        <label class="flex min-h-12 items-center gap-3" :class="disabled ? 'cursor-default' : 'cursor-pointer'">
            <input v-model="model" type="checkbox" class="size-5 rounded border-line accent-accent" :disabled="disabled">
            <span>{{ label }}</span>
        </label>
        <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    </div>
</template>
