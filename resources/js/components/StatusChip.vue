<script setup lang="ts">
// wrap: long content (an article list) wraps instead of widening the page.
withDefaults(defineProps<{ tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info'; wrap?: boolean }>(), { tone: 'neutral', wrap: false });
</script>

<template>
    <span
        class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium"
        :class="[wrap ? 'max-w-full rounded-lg break-words whitespace-normal' : 'rounded-full whitespace-nowrap', {
            neutral: 'bg-slate-100 text-slate-700',
            success: 'bg-emerald-100 text-emerald-800',
            warning: 'bg-amber-100 text-amber-900',
            danger: 'bg-red-100 text-red-800',
            info: 'bg-sky-100 text-sky-800',
        }[tone]]"
    >
        <slot />
    </span>
</template>
