<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ label: string; min?: number }>(), { min: 0 });
const model = defineModel<number>({ required: true });

function onInput(event: Event): void {
    const value = Number((event.target as HTMLInputElement).value.replace(',', '.'));
    if (Number.isFinite(value)) model.value = Math.max(0, value);
}
</script>

<template>
    <div class="inline-flex items-center rounded-lg border border-field bg-surface" role="group" :aria-label="label">
        <button type="button" class="size-12 text-xl text-accent disabled:text-line" :disabled="model <= min" :aria-label="t('Less')" @click="model = Math.max(min, model - 1)">−</button>
        <input
            :value="model"
            type="text"
            inputmode="numeric"
            :aria-label="label"
            class="h-12 w-14 border-x border-field text-center text-base tabular-nums focus:outline-2 focus:outline-accent/30"
            @change="onInput"
        >
        <button type="button" class="size-12 text-xl text-accent" :aria-label="t('More')" @click="model = model + 1">+</button>
    </div>
</template>
